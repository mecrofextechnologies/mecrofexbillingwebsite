<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Earnings - Keychele</title>

  <!-- Tailwind CSS -->
  <script src="https://cdn.tailwindcss.com"></script>
  <!-- Chart.js -->
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <!-- Font Awesome Kit -->
  <script src="https://kit.fontawesome.com/a2d9d5a04b.js" crossorigin="anonymous"></script>

  <style>
    body { background: #f3f4f6; }

    /* Card styling */
    .card { box-shadow: 0 6px 20px rgba(0,0,0,0.08); }

    /* Chart containers */
    .chart-wrap { height: 350px; position: relative; }
    .canvas-responsive { width: 100% !important; height: 100% !important; display: block; }

    /* Stat and title styling (desktop) */
    .card-title {
      font-size: 1rem;
      color: #6b7280;
    }
    .stat-value {
      font-size: 1.8rem;
      font-weight: 700;
      color: #111827;
      line-height: 1.1;
    }
    .icon-lg { font-size: 2.2rem; }

    /* Mobile view adjustments only */
    @media (max-width: 640px) {
      .chart-wrap { height: 220px; }
      .card-title { font-size: 0.75rem; }
      .stat-value { font-size: 1.2rem; word-break: break-word; }
      .icon-lg { font-size: 1.5rem; }
      .page-padding-bottom { padding-bottom: 5.5rem; }
    }
  </style>
</head>

<body class="min-h-screen page-padding-bottom">
  <!-- Header -->
  <header class="bg-gradient-to-r from-blue-600 to-indigo-600 text-white p-4 md:p-6 shadow-md">
    <div class="max-w-6xl mx-auto flex items-center justify-between">
      <h1 class="text-lg md:text-2xl font-semibold">Earnings Dashboard</h1>
      <button class="bg-blue-800 hover:bg-blue-900 text-white text-sm px-4 py-2 rounded-lg inline-flex items-center gap-2">
        <i class="fas fa-download"></i> Export
      </button>
    </div>
  </header>

  <!-- Main -->
  <main class="max-w-6xl mx-auto p-4 md:p-6">
    <!-- Summary cards -->
    <section class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">

      <div class="bg-white rounded-2xl p-5 md:p-6 card border-l-4 border-green-500 hover:shadow-xl transition">
        <div class="flex justify-between items-start">
          <div>
            <div class="card-title">Total Earnings</div>
            <div class="stat-value">KSh 145,600</div>
          </div>
          <i class="fas fa-sack-dollar text-green-500 icon-lg"></i>
        </div>
      </div>

      <div class="bg-white rounded-2xl p-5 md:p-6 card border-l-4 border-blue-500 hover:shadow-xl transition">
        <div class="flex justify-between items-start">
          <div>
            <div class="card-title">This Month</div>
            <div class="stat-value">KSh 21,420</div>
          </div>
          <i class="fas fa-calendar-alt text-blue-500 icon-lg"></i>
        </div>
      </div>

      <div class="bg-white rounded-2xl p-5 md:p-6 card border-l-4 border-yellow-400 hover:shadow-xl transition">
        <div class="flex justify-between items-start">
          <div>
            <div class="card-title">Pending</div>
            <div class="stat-value">KSh 4,200</div>
          </div>
          <i class="fas fa-hourglass-half text-yellow-400 icon-lg"></i>
        </div>
      </div>

      <div class="bg-white rounded-2xl p-5 md:p-6 card border-l-4 border-purple-500 hover:shadow-xl transition">
        <div class="flex justify-between items-start">
          <div>
            <div class="card-title">Completed</div>
            <div class="stat-value">KSh 141,400</div>
          </div>
          <i class="fas fa-circle-check text-purple-500 icon-lg"></i>
        </div>
      </div>

    </section>

    <!-- Charts -->
    <section class="grid grid-cols-1 md:grid-cols-2 gap-6">
      <div class="bg-white rounded-2xl p-5 card">
        <div class="flex justify-between items-center mb-3">
          <h4 class="text-base md:text-lg font-semibold text-slate-700">Earnings Growth</h4>
          <i class="fas fa-chart-line text-blue-500 icon-lg"></i>
        </div>
        <div class="chart-wrap"><canvas id="lineChart" class="canvas-responsive"></canvas></div>
      </div>

      <div class="bg-white rounded-2xl p-5 card">
        <div class="flex justify-between items-center mb-3">
          <h4 class="text-base md:text-lg font-semibold text-slate-700">Monthly Comparison</h4>
          <i class="fas fa-chart-column text-indigo-500 icon-lg"></i>
        </div>
        <div class="chart-wrap"><canvas id="barChart" class="canvas-responsive"></canvas></div>
      </div>
    </section>
  </main>

  <!-- Bottom navigation (mobile only) -->
  <nav class="fixed bottom-0 left-0 right-0 bg-white border-t border-gray-200 shadow-lg z-50 md:hidden">
    <div class="flex justify-around items-center h-14">
      <a href="#" class="flex flex-col items-center text-gray-600 hover:text-blue-600 transition">
        <i class="fas fa-home"></i>
        <span class="text-[11px] mt-1">Home</span>
      </a>
      <a href="#" class="flex flex-col items-center text-gray-600 hover:text-blue-600 transition">
        <i class="fas fa-wallet"></i>
        <span class="text-[11px] mt-1">Wallet</span>
      </a>
      <a href="#" class="flex items-center justify-center bg-blue-600 text-white rounded-full w-12 h-12 -mt-6 shadow-md">
        <i class="fas fa-plus"></i>
      </a>
      <a href="#" class="flex flex-col items-center text-gray-600 hover:text-blue-600 transition">
        <i class="fas fa-bell"></i>
        <span class="text-[11px] mt-1">Alerts</span>
      </a>
      <a href="#" class="flex flex-col items-center text-gray-600 hover:text-blue-600 transition">
        <i class="fas fa-user"></i>
        <span class="text-[11px] mt-1">Profile</span>
      </a>
    </div>
  </nav>

  <!-- Charts JS -->
  <script>
    // Line Chart (Earnings Growth)
    const lineCtx = document.getElementById('lineChart').getContext('2d');
    new Chart(lineCtx, {
      type: 'line',
      data: {
        labels: ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct'],
        datasets: [{
          label: 'Earnings (KSh)',
          data: [12000,14500,13800,16000,18200,17500,19500,21000,22500,24000],
          borderColor: '#2563EB',
          backgroundColor: 'rgba(37,99,235,0.15)',
          fill: true,
          tension: 0.4,
          pointRadius: 5,
          pointBackgroundColor: '#2563EB'
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true } }
      }
    });

    // Bar Chart (Monthly Comparison)
    const barCtx = document.getElementById('barChart').getContext('2d');
    new Chart(barCtx, {
      type: 'bar',
      data: {
        labels: ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct'],
        datasets: [{
          label: 'Earnings (KSh)',
          data: [12000,14500,13800,16000,18200,17500,19500,21000,22500,24000],
          backgroundColor: '#3B82F6',
          borderRadius: 8,
          barPercentage: 0.7
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true } }
      }
    });
  </script>
</body>
</html>
