<?php
declare(strict_types=1);

namespace LabelPrint;

use LabelPrint\Db\Db;
use LabelPrint\Model\ProfileRegistry;
use LabelPrint\Pdf\RasterizerFactory;
use LabelPrint\Queue\JobRepository;
use LabelPrint\Render\ZplLabelBuilder;
use LabelPrint\Storage\LabelRepository;
use LabelPrint\Storage\PdfFileRepository;
use LabelPrint\Support\Config;
use LabelPrint\Support\Log;

/**
 * Сборка объектов из конфига. Роль DI-контейнера здесь выполняет обычный класс
 * с ленивыми геттерами: зависимостей десяток, полноценный контейнер был бы
 * лишней сущностью в проекте без composer.
 */
final class App
{
    private ?Db $db = null;
    private ?Log $log = null;
    private ?ProfileRegistry $profiles = null;
    private ?RasterizerFactory $rasterizers = null;

    private function __construct(public readonly Config $config)
    {
    }

    public static function boot(?string $configPath = null): self
    {
        return new self(Config::load($configPath));
    }

    public function log(string $channel = 'labelprint'): Log
    {
        $this->log ??= Log::create(
            $this->config->string('log.level', 'info'),
            $this->config->get('log.file') === null ? null : $this->config->string('log.file'),
            $channel,
            $this->config->bool('log.json', false),
        );

        return $this->log->withChannel($channel);
    }

    public function db(): Db
    {
        return $this->db ??= Db::fromConfig($this->config);
    }

    public function profiles(): ProfileRegistry
    {
        return $this->profiles ??= ProfileRegistry::load();
    }

    public function jobs(): JobRepository
    {
        return new JobRepository($this->db());
    }

    public function files(): PdfFileRepository
    {
        return new PdfFileRepository($this->db());
    }

    public function labels(): LabelRepository
    {
        return new LabelRepository($this->db());
    }

    public function rasterizers(): RasterizerFactory
    {
        return $this->rasterizers ??= new RasterizerFactory($this->config, $this->log('raster'));
    }

    public function renderer(): RenderService
    {
        return new RenderService(
            pdfDir: $this->config->string('pdf_dir'),
            rasterizers: $this->rasterizers(),
            builder: new ZplLabelBuilder($this->config->bool('render.verify_roundtrip', true)),
            labels: $this->labels(),
            files: $this->files(),
            log: $this->log('render'),
            pdfinfoBinary: $this->pdfinfo(),
        );
    }

    public function scanner(): Scanner
    {
        return new Scanner(
            pdfDir: $this->config->string('pdf_dir'),
            files: $this->files(),
            jobs: $this->jobs(),
            profiles: $this->profiles(),
            log: $this->log('scan'),
            profileCodes: $this->profileCodes(),
            stableChecks: $this->config->int('scanner.stable_checks', 2),
            minAgeSeconds: $this->config->int('scanner.min_age_sec', 1),
            extensions: array_values(array_map('strval', $this->config->array('scanner.extensions') ?: ['pdf'])),
            recursive: $this->config->bool('scanner.recursive', true),
            maxAttempts: $this->config->int('worker.max_attempts', 3),
        );
    }

    public function worker(bool $withScanner = false): Worker
    {
        return new Worker(
            db: $this->db(),
            jobs: $this->jobs(),
            renderer: $this->renderer(),
            profiles: $this->profiles(),
            log: $this->log('worker'),
            pollIntervalMs: $this->config->int('worker.poll_interval_ms', 250),
            maxJobs: $this->config->int('worker.max_jobs', 500),
            maxLifetimeSeconds: $this->config->int('worker.max_lifetime_sec', 3600),
            leaseSeconds: $this->config->int('worker.lease_sec', 120),
            backoffBaseSeconds: $this->config->int('worker.backoff_base_sec', 5),
            scanner: $withScanner ? $this->scanner() : null,
            scanIntervalMs: $this->config->int('scanner.interval_ms', 1000),
        );
    }

    /**
     * Профили, под которые рендерится каждый новый PDF.
     *
     * @return list<string>
     */
    public function profileCodes(): array
    {
        $codes = $this->config->array('scanner.profiles');
        if ($codes === []) {
            return [$this->config->string('default_profile')];
        }

        return array_values(array_map('strval', $codes));
    }

    public function pdfinfo(): ?string
    {
        $path = $this->config->get('pdfinfo');

        return is_string($path) && $path !== '' && is_executable($path) ? $path : null;
    }
}
