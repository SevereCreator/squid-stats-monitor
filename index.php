<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Squid Proxy Monitor</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="bg-gradient-to-br from-slate-50 to-slate-100">
    <div class="min-h-screen p-6">
        <div class="max-w-7xl mx-auto">
            
            <div class="bg-white rounded-xl shadow-lg p-6 mb-6">
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <h1 class="text-3xl font-bold text-slate-800 flex items-center gap-3">
                            <i class="fas fa-server text-blue-600"></i>
                            Squid Proxy Monitor
                        </h1>
                        <p class="text-slate-600 mt-1">Мониторинг статистики в реальном времени</p>
                    </div>
                    <div class="flex items-center gap-4">
                        <button onclick="refreshStats()" class="flex items-center gap-2 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">
                            <i class="fas fa-sync-alt"></i> Обновить
                        </button>
                        <button onclick="exportToCSV()" class="flex items-center gap-2 px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition">
                            <i class="fas fa-download"></i> Export CSV
                        </button>
                    </div>
                </div>
                
                <div class="flex items-center gap-4 text-sm">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" id="autoRefresh" checked class="w-4 h-4">
                        <span class="text-slate-700">Авто-обновление</span>
                    </label>
                    <select id="refreshInterval" class="px-3 py-1 border border-slate-300 rounded-lg">
                        <option value="3000">3 сек</option>
                        <option value="5000" selected>5 сек</option>
                        <option value="10000">10 сек</option>
                        <option value="30000">30 сек</option>
                    </select>
                    <span class="text-slate-500 ml-auto flex items-center gap-2">
                        <i class="fas fa-clock"></i>
                        Обновлено: <span id="lastUpdate">--:--:--</span>
                    </span>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
                <div class="bg-gradient-to-br from-blue-500 to-blue-600 rounded-xl shadow-lg p-6 text-white">
                    <div class="flex items-center justify-between mb-2">
                        <i class="fas fa-chart-line text-3xl"></i>
                        <span class="text-blue-100 text-sm">Total</span>
                    </div>
                    <h3 class="text-3xl font-bold mb-1" id="totalTraffic">0 B</h3>
                    <p class="text-blue-100">Общий трафик</p>
                </div>

                <div class="bg-gradient-to-br from-green-500 to-green-600 rounded-xl shadow-lg p-6 text-white">
                    <div class="flex items-center justify-between mb-2">
                        <i class="fas fa-exchange-alt text-3xl"></i>
                        <span class="text-green-100 text-sm">Requests</span>
                    </div>
                    <h3 class="text-3xl font-bold mb-1" id="totalRequests">0</h3>
                    <p class="text-green-100">Всего запросов</p>
                </div>

                <div class="bg-gradient-to-br from-purple-500 to-purple-600 rounded-xl shadow-lg p-6 text-white">
                    <div class="flex items-center justify-between mb-2">
                        <i class="fas fa-users text-3xl"></i>
                        <span class="text-purple-100 text-sm">Active</span>
                    </div>
                    <h3 class="text-3xl font-bold mb-1" id="activeClients">0</h3>
                    <p class="text-purple-100">Активных клиентов</p>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-lg p-4 mb-6">
                <div class="flex items-center gap-4">
                    <button onclick="setViewMode('table')" id="btnTable" class="px-4 py-2 bg-blue-600 text-white rounded-lg transition">
                        <i class="fas fa-table mr-2"></i>Таблица
                    </button>
                    <button onclick="setViewMode('chart')" id="btnChart" class="px-4 py-2 bg-slate-100 text-slate-700 rounded-lg transition">
                        <i class="fas fa-chart-bar mr-2"></i>График
                    </button>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-lg p-4 mb-6" id="searchPanel">
                <div class="flex items-center gap-4">
                    <div class="flex-1 relative">
                        <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-slate-400"></i>
                        <input type="text" id="searchInput" placeholder="Поиск по IP или пользователю..." class="w-full pl-10 pr-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent" oninput="filterClients()">
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6" id="chartsPanel" style="display: none;">
                <div class="bg-white rounded-xl shadow-lg p-6">
                    <h3 class="text-lg font-semibold text-slate-800 mb-4">Топ 10 по трафику (GB)</h3>
                    <canvas id="barChart"></canvas>
                </div>
                <div class="bg-white rounded-xl shadow-lg p-6">
                    <h3 class="text-lg font-semibold text-slate-800 mb-4">Распределение трафика</h3>
                    <canvas id="pieChart"></canvas>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-lg overflow-hidden" id="tablePanel">
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-slate-100 border-b border-slate-200">
                            <tr>
                                <th onclick="sortTable('ip')" class="px-6 py-4 text-left text-sm font-semibold text-slate-700 cursor-pointer hover:bg-slate-200 transition">IP-адрес <i class="fas fa-sort ml-2"></i></th>
                                <th onclick="sortTable('user')" class="px-6 py-4 text-left text-sm font-semibold text-slate-700 cursor-pointer hover:bg-slate-200 transition">Пользователь <i class="fas fa-sort ml-2"></i></th>
                                <th onclick="sortTable('traffic')" class="px-6 py-4 text-left text-sm font-semibold text-slate-700 cursor-pointer hover:bg-slate-200 transition">Трафик <i class="fas fa-sort-down ml-2"></i></th>
                                <th onclick="sortTable('requests')" class="px-6 py-4 text-left text-sm font-semibold text-slate-700 cursor-pointer hover:bg-slate-200 transition">Запросы <i class="fas fa-sort ml-2"></i></th>
                                <th onclick="sortTable('hits')" class="px-6 py-4 text-left text-sm font-semibold text-slate-700 cursor-pointer hover:bg-slate-200 transition">Hit Rate <i class="fas fa-sort ml-2"></i></th>
                                <th onclick="sortTable('avgTime')" class="px-6 py-4 text-left text-sm font-semibold text-slate-700 cursor-pointer hover:bg-slate-200 transition">Avg Time <i class="fas fa-sort ml-2"></i></th>
                                <th class="px-6 py-4 text-left text-sm font-semibold text-slate-700">Визуализация</th>
                            </tr>
                        </thead>
                        <tbody id="clientsTableBody" class="divide-y divide-slate-200"></tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>

    <script>
        let statsData = { clients: [] };
        let sortConfig = { key: 'traffic', direction: 'desc' };
        let autoRefreshInterval = null;
        let viewMode = 'table';
        let barChartInstance = null;
        let pieChartInstance = null;

        document.addEventListener('DOMContentLoaded', function() {
            loadStats();
            setupAutoRefresh();
        });

        function loadStats() {
            fetch('api.php')
                .then(response => response.json())
                .then(data => {
                    statsData = data;
                    updateUI();
                })
                .catch(error => {
                    console.error('Error loading stats:', error);
                    document.getElementById('clientsTableBody').innerHTML = '<tr><td colspan="7" class="text-center py-12 text-red-500">Ошибка загрузки данных: ' + error.message + '</td></tr>';
                });
        }

        function refreshStats() {
            fetch('api.php?refresh=true')
                .then(response => response.json())
                .then(data => {
                    statsData = data;
                    updateUI();
                })
                .catch(error => console.error('Error:', error));
        }

        function updateUI() {
            document.getElementById('totalTraffic').textContent = formatBytes(statsData.totalTraffic);
            document.getElementById('totalRequests').textContent = formatNumber(statsData.totalRequests);
            document.getElementById('activeClients').textContent = statsData.activeClients;
            document.getElementById('lastUpdate').textContent = new Date(statsData.lastUpdate).toLocaleTimeString('ru-RU');
            updateTable();
            if (viewMode === 'chart') {
                updateCharts();
            }
        }

        function updateTable() {
            const tbody = document.getElementById('clientsTableBody');
            const clients = getSortedAndFilteredClients();
            
            if (clients.length === 0) {
                tbody.innerHTML = '<tr><td colspan="7" class="text-center py-12 text-slate-500">Нет данных по заданным фильтрам</td></tr>';
                return;
            }

            const maxTraffic = Math.max(...statsData.clients.map(c => c.traffic));
            
            tbody.innerHTML = clients.map(client => {
                const trafficPercent = (client.traffic / maxTraffic) * 100;
                const rowClass = getTrafficColorClass(client.traffic);
                const barClass = getTrafficBarClass(client.traffic);
                const hitClass = getHitRateClass(client.hits);
                
                return `
                    <tr class="hover:bg-slate-50 transition border-l-4 ${rowClass}">
                        <td class="px-6 py-4 text-sm font-mono text-slate-900">${client.ip}</td>
                        <td class="px-6 py-4 text-sm text-slate-700">${client.user}</td>
                        <td class="px-6 py-4 text-sm font-semibold text-slate-900">${formatBytes(client.traffic)}</td>
                        <td class="px-6 py-4 text-sm text-slate-700">${formatNumber(client.requests)}</td>
                        <td class="px-6 py-4 text-sm"><span class="px-2 py-1 rounded-full text-xs font-semibold ${hitClass}">${client.hits.toFixed(1)}%</span></td>
                        <td class="px-6 py-4 text-sm text-slate-700">${client.avgTime.toFixed(0)} ms</td>
                        <td class="px-6 py-4"><div class="w-full bg-slate-200 rounded-full h-2"><div class="${barClass} h-2 rounded-full" style="width: ${trafficPercent}%"></div></div></td>
                    </tr>
                `;
            }).join('');
        }

        function getSortedAndFilteredClients() {
            let clients = [...statsData.clients];
            const searchText = document.getElementById('searchInput').value.toLowerCase();
            if (searchText) {
                clients = clients.filter(c => c.ip.toLowerCase().includes(searchText) || c.user.toLowerCase().includes(searchText));
            }
            clients.sort((a, b) => {
                const aVal = a[sortConfig.key];
                const bVal = b[sortConfig.key];
                return sortConfig.direction === 'asc' ? (aVal > bVal ? 1 : -1) : (aVal < bVal ? 1 : -1);
            });
            return clients;
        }

        function sortTable(key) {
            if (sortConfig.key === key) {
                sortConfig.direction = sortConfig.direction === 'desc' ? 'asc' : 'desc';
            } else {
                sortConfig.key = key;
                sortConfig.direction = 'desc';
            }
            updateTable();
        }

        function filterClients() {
            updateTable();
        }

        function setViewMode(mode) {
            viewMode = mode;
            document.getElementById('tablePanel').style.display = mode === 'table' ? 'block' : 'none';
            document.getElementById('chartsPanel').style.display = mode === 'chart' ? 'grid' : 'none';
            document.getElementById('searchPanel').style.display = mode === 'table' ? 'block' : 'none';
            document.getElementById('btnTable').className = mode === 'table' ? 'px-4 py-2 bg-blue-600 text-white rounded-lg transition' : 'px-4 py-2 bg-slate-100 text-slate-700 rounded-lg transition';
            document.getElementById('btnChart').className = mode === 'chart' ? 'px-4 py-2 bg-blue-600 text-white rounded-lg transition' : 'px-4 py-2 bg-slate-100 text-slate-700 rounded-lg transition';
            if (mode === 'chart') {
                updateCharts();
            }
        }

        function updateCharts() {
            const topClients = getSortedAndFilteredClients().slice(0, 10);
            const labels = topClients.map(c => c.user);
            const trafficData = topClients.map(c => (c.traffic / (1024 * 1024 * 1024)).toFixed(2));
            
            const barCtx = document.getElementById('barChart').getContext('2d');
            if (barChartInstance) barChartInstance.destroy();
            barChartInstance = new Chart(barCtx, {
                type: 'bar',
                data: { labels: labels, datasets: [{ label: 'Трафик (GB)', data: trafficData, backgroundColor: 'rgba(59, 130, 246, 0.8)', borderColor: 'rgba(59, 130, 246, 1)', borderWidth: 1 }] },
                options: { responsive: true, maintainAspectRatio: true, scales: { y: { beginAtZero: true } } }
            });
            
            const pieCtx = document.getElementById('pieChart').getContext('2d');
            if (pieChartInstance) pieChartInstance.destroy();
            pieChartInstance = new Chart(pieCtx, {
                type: 'pie',
                data: { labels: labels, datasets: [{ data: trafficData, backgroundColor: ['#3b82f6', '#ef4444', '#10b981', '#f59e0b', '#8b5cf6', '#ec4899', '#14b8a6', '#f97316'] }] },
                options: { responsive: true, maintainAspectRatio: true }
            });
        }

        function exportToCSV() {
            const clients = getSortedAndFilteredClients();
            const headers = ['IP', 'User', 'Traffic', 'Requests', 'Hit Rate %', 'Avg Time (ms)'];
            const rows = clients.map(c => [c.ip, c.user, c.traffic, c.requests, c.hits.toFixed(2), c.avgTime.toFixed(0)]);
            const csv = [headers, ...rows].map(row => row.join(',')).join('\n');
            const blob = new Blob([csv], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `squid-stats-${Date.now()}.csv`;
            a.click();
        }

        function setupAutoRefresh() {
            const checkbox = document.getElementById('autoRefresh');
            const select = document.getElementById('refreshInterval');
            const updateAutoRefresh = () => {
                if (autoRefreshInterval) clearInterval(autoRefreshInterval);
                if (checkbox.checked) {
                    const interval = parseInt(select.value);
                    autoRefreshInterval = setInterval(loadStats, interval);
                }
            };
            checkbox.addEventListener('change', updateAutoRefresh);
            select.addEventListener('change', updateAutoRefresh);
            updateAutoRefresh();
        }

        function formatBytes(bytes) {
            if (bytes === 0) return '0 B';
            const k = 1024;
            const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }

        function formatNumber(num) {
            return new Intl.NumberFormat('ru-RU').format(num);
        }

        function getTrafficColorClass(traffic) {
            const gb = traffic / (1024 * 1024 * 1024);
            if (gb > 5) return 'bg-red-100 border-red-300';
            if (gb > 3) return 'bg-orange-100 border-orange-300';
            if (gb > 1) return 'bg-yellow-100 border-yellow-300';
            return 'bg-green-100 border-green-300';
        }

        function getTrafficBarClass(traffic) {
            const gb = traffic / (1024 * 1024 * 1024);
            if (gb > 5) return 'bg-red-500';
            if (gb > 3) return 'bg-orange-500';
            if (gb > 1) return 'bg-yellow-500';
            return 'bg-green-500';
        }

        function getHitRateClass(hits) {
            if (hits > 70) return 'bg-green-100 text-green-800';
            if (hits > 40) return 'bg-yellow-100 text-yellow-800';
            return 'bg-red-100 text-red-800';
        }
    </script>
</body>
</html>
