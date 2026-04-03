<?php
/**
 * SquidStatsParser.php
 * Модуль парсинга логов прокси-сервера Squid
 *
 * Часть системы: Squid Stats Monitor
 * Репозиторий: https://github.com/SevereCreator/squid-stats-monitor
 */

require_once __DIR__ . '/Logger.php'; // [LOGGING]

class SquidStatsParser {

    private $logFile;
    private $cacheFile;
    private $cacheLifetime = 60; // секунды

    /**
     * Конструктор класса
     * @param string $logFile Путь к файлу логов Squid
     */
    public function __construct($logFile = '/var/log/squid/access.log') {
        $this->logFile = $logFile;
        $this->cacheFile = sys_get_temp_dir() . '/squid_stats_cache.json';
    }

    // ─────────────────────────────────────────────────────────────────────
    // Публичные методы
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Получить статистику (с кэшированием)
     * @param bool $forceRefresh Принудительное обновление кэша
     * @return array
     */
    public function getStats($forceRefresh = false) {
        $log = Logger::getInstance(); // [LOGGING]

        // Проверка наличия и актуальности кэша
        if (!$forceRefresh && file_exists($this->cacheFile)) {
            $cacheTime   = filemtime($this->cacheFile);
            $currentTime = time();
            if ($currentTime - $cacheTime < $this->cacheLifetime) {
                $log->info('Статистика возвращена из кэша', [ // [LOGGING]
                    'cacheAge'  => $currentTime - $cacheTime, // [LOGGING]
                    'cacheFile' => $this->cacheFile, // [LOGGING]
                ]); // [LOGGING]
                $cachedData = file_get_contents($this->cacheFile);
                return json_decode($cachedData, true);
            }
        }

        $log->info('Кэш устарел или сброшен, запускаем парсинг', [ // [LOGGING]
            'forceRefresh' => $forceRefresh, // [LOGGING]
            'logFile'      => $this->logFile, // [LOGGING]
        ]); // [LOGGING]

        // Парсинг логов
        $stats = $this->parseLogFile();

        // Сохранение в кэш
        file_put_contents($this->cacheFile, json_encode($stats));
        $log->info('Кэш обновлён', ['cacheFile' => $this->cacheFile]); // [LOGGING]

        return $stats;
    }

    /**
     * Форматировать байты в читаемый вид
     * @param int $bytes
     * @return string
     */
    public static function formatBytes($bytes) {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        for ($i = 0; $bytes >= 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }

    /**
     * Проверить, устарел ли кэш
     * @return bool
     */
    public function isExpired() {
        if (!file_exists($this->cacheFile)) {
            return true;
        }
        return (time() - filemtime($this->cacheFile)) >= $this->cacheLifetime;
    }

    /**
     * Установить время жизни кэша
     * @param int $seconds
     */
    public function setCacheLifetime($seconds) {
        $this->cacheLifetime = (int)$seconds;
        Logger::getInstance()->debug('Установлено время жизни кэша', ['seconds' => $seconds]); // [LOGGING]
    }

    /**
     * Принудительно очистить кэш
     */
    public function clearCache() {
        $log = Logger::getInstance(); // [LOGGING]
        if (file_exists($this->cacheFile)) {
            unlink($this->cacheFile);
            $log->info('Кэш принудительно очищен', ['cacheFile' => $this->cacheFile]); // [LOGGING]
        } else {
            $log->debug('clearCache вызван, но файл кэша не существует'); // [LOGGING]
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Приватные методы
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Основной метод парсинга лог-файла
     * @return array
     */
    private function parseLogFile() {
        $log = Logger::getInstance(); // [LOGGING]

        if (!file_exists($this->logFile)) {
            $log->error('Лог-файл не найден', ['path' => $this->logFile]); // [LOGGING]
            throw new Exception("Log file not found: {$this->logFile}");
        }
        if (!is_readable($this->logFile)) {
            $log->error('Лог-файл недоступен для чтения', ['path' => $this->logFile]); // [LOGGING]
            throw new Exception("Log file is not readable: {$this->logFile}");
        }

        // Читаем последние 10 000 строк (оптимизация для больших логов)
        $lines = $this->tailFile($this->logFile, 10000);
        $log->info('Прочитаны строки из лог-файла', [ // [LOGGING]
            'path'  => $this->logFile, // [LOGGING]
            'count' => count($lines), // [LOGGING]
        ]); // [LOGGING]

        // Структуры для агрегации
        $clients       = [];
        $totalTraffic  = 0;
        $totalRequests = 0;
        $skippedLines  = 0; // [LOGGING]
        $hitCodes      = ['TCP_HIT', 'TCP_MEM_HIT', 'TCP_REFRESH_HIT', 'TCP_IMS_HIT'];

        foreach ($lines as $line) {
            $parsed = $this->parseLogLine($line);
            if (!$parsed) {
                $skippedLines++; // [LOGGING]
                continue;
            }

            $ip = $parsed['client_ip'];

            // Инициализация записи клиента при первой встрече
            if (!isset($clients[$ip])) {
                $clients[$ip] = [
                    'ip'        => $ip,
                    'user'      => $parsed['user'],
                    'traffic'   => 0,
                    'requests'  => 0,
                    'hits'      => 0,
                    'misses'    => 0,
                    'totalTime' => 0,
                ];
            }

            // Накопление метрик
            $clients[$ip]['traffic']   += $parsed['bytes'];
            $clients[$ip]['requests']++;
            $clients[$ip]['totalTime'] += $parsed['elapsed'];

            // Классификация: HIT / MISS
            if (in_array($parsed['result_code'], $hitCodes)) {
                $clients[$ip]['hits']++;
            } else {
                $clients[$ip]['misses']++;
            }

            // Сохраняем актуальное имя пользователя (не анонимное)
            if ($parsed['user'] !== 'anonymous' && $clients[$ip]['user'] === 'anonymous') {
                $clients[$ip]['user'] = $parsed['user'];
            }

            $totalTraffic  += $parsed['bytes'];
            $totalRequests++;
        }

        $log->info('Парсинг завершён', [ // [LOGGING]
            'totalLines'    => count($lines), // [LOGGING]
            'parsedLines'   => $totalRequests, // [LOGGING]
            'skippedLines'  => $skippedLines, // [LOGGING]
            'uniqueClients' => count($clients), // [LOGGING]
            'totalTraffic'  => $totalTraffic, // [LOGGING]
        ]); // [LOGGING]

        // Финальная обработка: вычисление производных метрик
        $clientsArray = [];
        foreach ($clients as $client) {
            $hitRate = $client['requests'] > 0
                ? ($client['hits'] / $client['requests']) * 100
                : 0;

            $avgTime = $client['requests'] > 0
                ? $client['totalTime'] / $client['requests']
                : 0;

            $clientsArray[] = [
                'ip'       => $client['ip'],
                'user'     => $client['user'],
                'traffic'  => (int)$client['traffic'],
                'requests' => (int)$client['requests'],
                'hits'     => round($hitRate, 2),
                'avgTime'  => round($avgTime, 2),
            ];
        }

        // Сортировка по трафику (по убыванию) — дефолт
        usort($clientsArray, fn($a, $b) => $b['traffic'] <=> $a['traffic']);

        // Общий Hit Rate
        $totalHits    = array_sum(array_column($clients, 'hits'));
        $totalHitRate = $totalRequests > 0
            ? round(($totalHits / $totalRequests) * 100, 2)
            : 0;

        $log->info('Итоговая статистика сформирована', [ // [LOGGING]
            'activeClients' => count($clientsArray), // [LOGGING]
            'totalRequests' => $totalRequests, // [LOGGING]
            'totalHitRate'  => $totalHitRate, // [LOGGING]
        ]); // [LOGGING]

        return [
            'totalTraffic'  => (int)$totalTraffic,
            'totalRequests' => (int)$totalRequests,
            'activeClients' => count($clientsArray),
            'totalHitRate'  => $totalHitRate,
            'lastUpdate'    => date('c'),
            'clients'       => $clientsArray,
        ];
    }

    /**
     * Разобрать одну строку лог-файла Squid
     * Формат: timestamp elapsed client_ip result/status bytes method url user hier/peer content_type
     *
     * @param string $line
     * @return array|null
     */
    private function parseLogLine($line) {
        $line = trim($line);
        if (empty($line)) {
            return null;
        }

        // Разбивка по пробелам
        $parts = preg_split('/\s+/', $line);

        // Валидация: минимум 10 полей
        if (count($parts) < 10) {
            Logger::getInstance()->debug('Строка пропущена: недостаточно полей', [ // [LOGGING]
                'fields'  => count($parts), // [LOGGING]
                'preview' => mb_substr($line, 0, 80), // [LOGGING]
            ]); // [LOGGING]
            return null;
        }

        // Разбор result_code/status (например TCP_MISS/200)
        $resultParts = explode('/', $parts[3]);

        return [
            'timestamp'   => $parts[0],
            'elapsed'     => (int)$parts[1],
            'client_ip'   => $parts[2],
            'result_code' => $resultParts[0],
            'status'      => $resultParts[1] ?? '',
            'bytes'       => (int)$parts[4],
            'method'      => $parts[5],
            'url'         => $parts[6],
            'user'        => ($parts[7] !== '-') ? $parts[7] : 'anonymous',
        ];
    }

    /**
     * Эффективное чтение последних N строк файла (аналог unix tail)
     * Не загружает весь файл в память — O(N) по памяти.
     *
     * @param string $file   Путь к файлу
     * @param int    $lines  Количество строк
     * @return array
     */
    private function tailFile($file, $lines = 1000) {
        $log = Logger::getInstance(); // [LOGGING]

        $handle = fopen($file, 'r');
        if (!$handle) {
            $log->error('Не удалось открыть лог-файл для чтения (tailFile)', ['file' => $file]); // [LOGGING]
            return [];
        }

        $log->debug('tailFile: начало чтения файла', ['file' => $file, 'requestedLines' => $lines]); // [LOGGING]

        $linecounter = $lines;
        $pos         = -2;          // начинаем с предпоследнего символа
        $beginning   = false;
        $text        = [];

        while ($linecounter > 0) {
            $t = ' ';

            // Ищем символ новой строки, двигаясь от конца к началу
            while ($t !== "\n") {
                if (fseek($handle, $pos, SEEK_END) === -1) {
                    $beginning = true;
                    break;
                }
                $t = fgetc($handle);
                $pos--;
            }

            $linecounter--;

            if ($beginning) {
                rewind($handle);
            }

            $text[$lines - $linecounter - 1] = fgets($handle);

            if ($beginning) {
                break;
            }
        }

        fclose($handle);

        // Переворачиваем — читали с конца
        $result = array_reverse($text);
        $log->debug('tailFile: чтение завершено', ['returnedLines' => count($result)]); // [LOGGING]

        return $result;
    }
}
