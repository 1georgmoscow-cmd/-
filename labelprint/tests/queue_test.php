<?php
declare(strict_types=1);

use LabelPrint\Queue\JobRepository;

return [
    'задержка растёт вдвое, а не в степень' => static function (): void {
        // Без разброса: base * 2^(попытка-1)
        assertSame(5, JobRepository::backoffSeconds(5, 1, 0.0));
        assertSame(10, JobRepository::backoffSeconds(5, 2, 0.0));
        assertSame(20, JobRepository::backoffSeconds(5, 3, 0.0));
        assertSame(40, JobRepository::backoffSeconds(5, 4, 0.0));
        assertSame(80, JobRepository::backoffSeconds(5, 5, 0.0));

        // Прежняя формула base^попытка на пятой попытке дала бы 3125 секунд —
        // почти час простоя вместо полутора минут.
        assertTrue(JobRepository::backoffSeconds(5, 5, 0.0) < 100);
    },

    'задержка ограничена часом' => static function (): void {
        assertSame(3600, JobRepository::backoffSeconds(5, 20, 0.0));
        assertSame(3600, JobRepository::backoffSeconds(60, 30, 0.0));
    },

    'разброс держится в пределах 20 процентов' => static function (): void {
        // Разброс нужен, чтобы задания, упавшие во время недоступности базы,
        // не ломились обратно все одновременно.
        $base = 100;
        $seen = [];
        for ($i = 0; $i < 200; $i++) {
            $delay = JobRepository::backoffSeconds($base, 1);
            assertTrue($delay >= 80 && $delay <= 120, "задержка {$delay} вне диапазона 80..120");
            $seen[$delay] = true;
        }

        assertTrue(count($seen) > 5, 'значения должны различаться, иначе разброса нет');
    },

    'нулевая и отрицательная база не ломают расчёт' => static function (): void {
        assertTrue(JobRepository::backoffSeconds(0, 1, 0.0) >= 1);
        assertTrue(JobRepository::backoffSeconds(-5, 3, 0.0) >= 1);
    },
];
