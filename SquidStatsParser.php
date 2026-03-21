<?php
class SquidStatsParser {
    private $logFile;
    private $cacheFile;
    private $cacheLifetime = 60;
    
    public function __construct($logFile = '/var/log/squid/access.log') {
        $this->logFile = $logFile;
        $this->cacheFile = sys_get_temp_dir() . '/squid_stats_cache.json';
    }
    
    public function getStats($forceRefresh = false) {
        if (!$forceRefresh && file_exists($this->cacheFile)) {
            $cacheTime = filemtime($this->cacheFile);
            if (time() - $cacheTime < $this->cacheLifetime) {
                return json_decode(file_get_contents($this->cacheFile), true);
            }
        }
        
        $stats = $this->parseLogFile();
        file_put_contents($this->cacheFile, json_encode($stats));
        return $stats;
    }
    
    private function parseLogFile() {
        $clients = [];
        $totalTraffic = 0;
        $totalRequests = 0;
        
        if (!file_exists($this->logFile)) {
            return $this->generateMockData();
        }
        
        $lines = $this->tailFile($this->logFile, 10000);
        
        foreach ($lines as $line) {
            $parsed = $this->parseLogLine($line);
            if ($parsed) {
                $ip = $parsed['client_ip'];
                
                if (!isset($clients[$ip])) {
                    $clients[$ip] = [
                        'ip' => $ip,
                        'user' => $parsed['user'],
                        'traffic' => 0,
                        'requests' => 0,
                        'hits' => 0,
                        'misses' => 0,
                        'totalTime' => 0
                    ];
                }
                
                $clients[$ip]['traffic'] += $parsed['bytes'];
                $clients[$ip]['requests']++;
                $clients[$ip]['totalTime'] += $parsed['elapsed'];
                
                if ($parsed['result_code'] === 'TCP_HIT' || $parsed['result_code'] === 'TCP_MEM_HIT') {
                    $clients[$ip]['hits']++;
                } else {
                    $clients[$ip]['misses']++;
                }
                
                $totalTraffic += $parsed['bytes'];
                $totalRequests++;
            }
        }
        
        $clientsArray = [];
        foreach ($clients as $client) {
            $hitRate = $client['requests'] > 0 ? ($client['hits'] / $client['requests']) * 100 : 0;
            $avgTime = $client['requests'] > 0 ? $client['totalTime'] / $client['requests'] : 0;
                
            $clientsArray[] = [
                'ip' => $client['ip'],
                'user' => $client['user'],
                'traffic' => (int)$client['traffic'],
                'requests' => (int)$client['requests'],
                'hits' => round($hitRate, 2),
                'avgTime' => round($avgTime, 2)
            ];
        }
        
        return [
            'totalTraffic' => (int)$totalTraffic,
            'totalRequests' => (int)$totalRequests,
            'activeClients' => count($clientsArray),
            'lastUpdate' => date('c'),
            'clients' => $clientsArray
        ];
    }
    
    private function parseLogLine($line) {
        $parts = preg_split('/\s+/', trim($line));
        
        if (count($parts) < 10) {
            return null;
        }
        
        return [
            'timestamp' => $parts[0],
            'elapsed' => (int)$parts[1],
            'client_ip' => $parts[2],
            'result_code' => explode('/', $parts[3])[0],
            'status' => explode('/', $parts[3])[1] ?? '',
            'bytes' => (int)$parts[4],
            'method' => $parts[5],
            'url' => $parts[6],
            'user' => $parts[7] !== '-' ? $parts[7] : 'anonymous',
        ];
    }
    
    private function tailFile($file, $lines = 1000) {
        $handle = fopen($file, 'r');
        if (!$handle) {
            return [];
        }
        
        $linecounter = $lines;
        $pos = -2;
        $beginning = false;
        $text = [];
        
        while ($linecounter > 0) {
            $t = " ";
            while ($t != "\n") {
                if (fseek($handle, $pos, SEEK_END) == -1) {
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
            if ($beginning) break;
        }
        
        fclose($handle);
        return array_reverse($text);
    }
    
    private function generateMockData() {
        $users = ['john.doe', 'jane.smith', 'bob.johnson', 'alice.williams', 'charlie.brown', 'david.miller', 'emma.davis', 'frank.wilson'];
        $clients = [];
        
        foreach ($users as $i => $user) {
            $clients[] = [
                'ip' => '192.168.1.' . (10 + $i * 5),
                'user' => $user,
                'traffic' => rand(1000000000, 8000000000),
                'requests' => rand(1000, 15000),
                'hits' => round(rand(30, 95) + rand(0, 100) / 100, 2),
                'avgTime' => round(rand(100, 2000) + rand(0, 100) / 100, 2)
            ];
        }
        
        $totalTraffic = array_sum(array_column($clients, 'traffic'));
        $totalRequests = array_sum(array_column($clients, 'requests'));
        
        return [
            'totalTraffic' => $totalTraffic,
            'totalRequests' => $totalRequests,
            'activeClients' => count($clients),
            'lastUpdate' => date('c'),
            'clients' => $clients
        ];
    }
}
?>
