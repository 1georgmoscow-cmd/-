<?php
declare(strict_types=1);

namespace LabelPrint;

use LabelPrint\Model\ProfileRegistry;
use LabelPrint\Queue\JobRepository;
use LabelPrint\Storage\PdfFileRepository;
use LabelPrint\Support\Log;

/**
 * Обход каталога /upload/pdf и постановка новых файлов в очередь рендеринга.
 *
 * Главная тонкость — не схватить файл, который ещё дописывается. Веб-сервер
 * или FTP льют PDF по частям, и половина файла с точки зрения Ghostscript
 * это просто битый PDF. Поэтому файл считается готовым, только когда он
 * «отстоялся»: не менялся между двумя обходами и старше min_age_sec.
 *
 * Идеальный вариант — чтобы загрузчик писал во временное имя и делал rename()
 * (в пределах одной файловой системы это атомарно). Тогда проверки не нужны,
 * и min_age_sec можно ставить в 0.
 */
final class Scanner
{
    /** @var array<string,array{size:int,mtime:int,seen:int}> */
    private array $pending = [];

    /**
     * @param list<string> $profileCodes профили, под которые рендерим каждый новый PDF
     * @param list<string> $extensions
     */
    public function __construct(
        private readonly string $pdfDir,
        private readonly PdfFileRepository $files,
        private readonly JobRepository $jobs,
        private readonly ProfileRegistry $profiles,
        private readonly Log $log,
        private readonly array $profileCodes,
        private readonly int $stableChecks = 1,
        private readonly int $minAgeSeconds = 2,
        private readonly array $extensions = ['pdf'],
        private readonly bool $recursive = true,
        private readonly int $maxAttempts = 3,
        /**
         * Регулярное выражение с одной скобкой захвата, по которому из ИМЕНИ файла
         * достаётся номер отправления. По умолчанию — имя без расширения, то есть
         * файл 0494051806-0963-1.pdf даёт номер 0494051806-0963-1.
         * null — не выводить номер из имени вовсе.
         */
        private readonly ?string $postingIdPattern = '/^(.+)\.pdf$/i',
    ) {
        if ($this->profileCodes === []) {
            throw new \InvalidArgumentException('Сканеру не задано ни одного профиля');
        }
        foreach ($this->profileCodes as $code) {
            $this->profiles->get($code);   // падаем сразу, а не на первом файле
        }
    }

    /**
     * Один проход по каталогу.
     *
     * @return array{scanned:int,enqueued:int,skipped:int}
     */
    public function scan(): array
    {
        $base = realpath($this->pdfDir);
        if ($base === false) {
            throw new \RuntimeException("Каталог с PDF не найден: {$this->pdfDir}");
        }

        $known = $this->files->knownFiles();
        $scanned = 0;
        $enqueued = 0;
        $skipped = 0;
        $now = time();
        $seen = [];

        foreach ($this->walk($base) as $absolute) {
            $scanned++;
            $relative = substr($absolute, strlen($base) + 1);
            $seen[$relative] = true;

            $stat = @stat($absolute);
            if ($stat === false) {
                continue;   // файл исчез между listdir и stat
            }

            $size = (int) $stat['size'];
            $mtime = (int) $stat['mtime'];

            if ($size === 0) {
                $skipped++;
                continue;
            }

            // Уже известен и не менялся — трогать нечего.
            $previous = $known[$relative] ?? null;
            if ($previous !== null && $previous['size'] === $size && $previous['mtime'] === $mtime) {
                continue;
            }

            if (!$this->isStable($relative, $size, $mtime, $now)) {
                $skipped++;
                continue;
            }

            $sha256 = hash_file('sha256', $absolute);
            if ($sha256 === false) {
                $this->log->warning('не удалось посчитать хэш файла', ['path' => $relative]);
                $skipped++;
                continue;
            }

            $file = $this->files->upsert($relative, $sha256, $size, $mtime, $this->postingId($relative));
            unset($this->pending[$relative]);

            foreach ($this->profileCodes as $code) {
                $profile = $this->profiles->get($code);
                $this->jobs->enqueue(
                    pdfFileId: $file['id'],
                    profileCode: $code,
                    profileFingerprint: $profile->fingerprint(),
                    maxAttempts: $this->maxAttempts,
                    // PDF, перезаписанный по тому же пути, обязан отрендериться заново.
                    contentChanged: $file['changed'],
                );
                $enqueued++;
            }

            $this->log->info('файл поставлен в очередь', [
                'path' => $relative,
                'size' => $size,
                'profiles' => count($this->profileCodes),
                'new' => $file['changed'],
            ]);
        }

        // Забываем файлы, которых больше нет: иначе в долгоживущем процессе
        // накапливаются записи об удалённых и о вечно недописанных файлах.
        $this->pending = array_intersect_key($this->pending, $seen);

        return ['scanned' => $scanned, 'enqueued' => $enqueued, 'skipped' => $skipped];
    }

    /** Достаёт номер отправления из имени файла по настроенному шаблону. */
    private function postingId(string $relativePath): ?string
    {
        if ($this->postingIdPattern === null) {
            return null;
        }

        $name = basename($relativePath);

        if (preg_match($this->postingIdPattern, $name, $m) !== 1) {
            return null;
        }

        $postingId = trim($m[1] ?? '');

        return $postingId === '' ? null : mb_substr($postingId, 0, 128);
    }

    /**
     * Файл считается дописанным.
     *
     * Основной признак — возраст mtime: запись в файл его обновляет, поэтому
     * «не менялся последние min_age_sec секунд» отсекает недолитые загрузки.
     * Этот признак работает и при разовом запуске сканера.
     *
     * Дополнительная проверка stable_checks требует нескольких одинаковых
     * наблюдений подряд. Счётчик живёт в памяти процесса, поэтому имеет смысл
     * только в режиме --watch; при значении 1 (по умолчанию) он ничего не меняет.
     */
    private function isStable(string $relative, int $size, int $mtime, int $now): bool
    {
        if ($now - $mtime < $this->minAgeSeconds) {
            return false;
        }

        if ($this->stableChecks <= 1) {
            return true;
        }

        $prev = $this->pending[$relative] ?? null;

        if ($prev === null || $prev['size'] !== $size || $prev['mtime'] !== $mtime) {
            $this->pending[$relative] = ['size' => $size, 'mtime' => $mtime, 'seen' => 1];

            return false;
        }

        $this->pending[$relative]['seen'] = ++$prev['seen'];

        return $prev['seen'] >= $this->stableChecks;
    }

    /**
     * Обход каталога. Используется итератор, а не glob: каталог с этикетками
     * легко разрастается до десятков тысяч файлов, и загонять весь список
     * в память ради одного прохода незачем.
     *
     * @return \Generator<string>
     */
    private function walk(string $base): \Generator
    {
        $flags = \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::UNIX_PATHS;

        $iterator = $this->recursive
            ? new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($base, $flags),
                \RecursiveIteratorIterator::LEAVES_ONLY,
            )
            : new \FilesystemIterator($base, $flags);

        $extensions = array_map('strtolower', $this->extensions);

        /** @var \SplFileInfo $entry */
        foreach ($iterator as $entry) {
            if (!$entry->isFile()) {
                continue;
            }
            if (!in_array(strtolower($entry->getExtension()), $extensions, true)) {
                continue;
            }
            // Частичные загрузки принято помечать точкой или суффиксом .part/.tmp
            $name = $entry->getFilename();
            if (str_starts_with($name, '.') || str_ends_with($name, '.part') || str_ends_with($name, '.tmp')) {
                continue;
            }

            yield $entry->getPathname();
        }
    }
}
