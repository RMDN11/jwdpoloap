<?php
session_start();
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header('Location: monggo.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>WhatsApp Canggih - JWD</title>
  <link rel="icon" href="LOGOJWD.png?v=<?= time() ?>" type="image/png">
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://cdnjs.cloudflare.com/ajax/libs/phosphor-icons/1.4.2/css/phosphor.css" rel="stylesheet">
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');
    body { font-family: 'Inter', sans-serif; }
    .card-icon-container { transition: transform 0.3s ease, box-shadow 0.3s ease; }
    .card:hover .card-icon-container { transform: scale(1.1); box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05); }
    .card-title { transition: color 0.3s ease; }
    .card:hover .card-title { color: var(--tw-color-primary); }
    .gradient-background-1 { background: linear-gradient(45deg, #16a34a, #4ade80); }
    .gradient-background-2 { background: linear-gradient(45deg, #3b82f6, #60a5fa); }
    .gradient-background-3 { background: linear-gradient(45deg, #8b5cf6, #c084fc); }
    .gradient-background-4 { background: linear-gradient(45deg, #f97316, #fb923c); }
  </style>
</head>
<body class="bg-slate-100 text-slate-800 antialiased">

  <div id="app" class="max-w-7xl mx-auto min-h-screen bg-white md:shadow-2xl md:rounded-xl">
    
    <header class="bg-gradient-to-br from-green-600 to-green-800 text-white p-6 shadow-md md:rounded-t-xl rounded-b-3xl">
      <div class="flex items-center justify-between">
        <div class="flex items-center space-x-3">
          <div class="p-2 bg-white/20 rounded-full"><i class="ph-broadcast text-xl"></i></div>
          <div>
            <h1 class="text-xl md:text-2xl font-bold">Dashboard WhatsApp Jawwada Tahfidz Private</h1>
          </div>
        </div>
      </div>
    </header>

    <main class="p-6 md:p-10">
      <?php if (!empty($notification)): ?>
      <div class="mb-6 p-4 rounded-xl shadow-sm <?php echo $notificationType === 'success' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?> flex items-center space-x-3 transition-all duration-300">
        <div class="<?php echo $notificationType === 'success' ? 'text-green-500' : 'text-red-500'; ?>"><i class="ph-info text-2xl"></i></div>
        <p class="text-sm md:text-base font-medium"><?php echo htmlspecialchars($notification); ?></p>
      </div>
      <?php endif; ?>

      <div class="grid grid-cols-2 md:grid-cols-4 gap-4 md:gap-6">
        
        <a href="reminder.php" class="card bg-white p-5 md:p-6 rounded-2xl shadow-lg border border-slate-200 hover:shadow-xl hover:-translate-y-1 transition-all duration-300">
          <div class="card-icon-container w-12 h-12 flex items-center justify-center rounded-xl mb-3 gradient-background-1 text-white shadow-md">
            <i class="ph-chat-circle-dots text-2xl md:text-3xl"></i>
          </div>
          <h2 class="card-title text-base md:text-lg font-semibold text-slate-700 mb-1">Kirim Reminder</h2>
          <p class="text-xs md:text-sm text-slate-500">Pesan pengingat pembayaran.</p>
        </a>

        <a href="promosi.php" class="card bg-white p-5 md:p-6 rounded-2xl shadow-lg border border-slate-200 hover:shadow-xl hover:-translate-y-1 transition-all duration-300">
          <div class="card-icon-container w-12 h-12 flex items-center justify-center rounded-xl mb-3 gradient-background-2 text-white shadow-md">
            <i class="ph-megaphone-simple text-2xl md:text-3xl"></i>
          </div>
          <h2 class="card-title text-base md:text-lg font-semibold text-slate-700 mb-1">Kirim Promosi</h2>
          <p class="text-xs md:text-sm text-slate-500">Promosi dengan gambar & filter.</p>
        </a>

        <a href="kelola_reminder.php" class="card bg-white p-5 md:p-6 rounded-2xl shadow-lg border border-slate-200 hover:shadow-xl hover:-translate-y-1 transition-all duration-300">
          <div class="card-icon-container w-12 h-12 flex items-center justify-center rounded-xl mb-3 gradient-background-3 text-white shadow-md">
            <i class="ph-clock-counter-clockwise text-2xl md:text-3xl"></i>
          </div>
          <h2 class="card-title text-base md:text-lg font-semibold text-slate-700 mb-1">Kelola Reminder</h2>
          <p class="text-xs md:text-sm text-slate-500">Lihat permintaan peserta.</p>
        </a>

        <a href="kirimgrup.php" class="card bg-white p-5 md:p-6 rounded-2xl shadow-lg border border-slate-200 hover:shadow-xl hover:-translate-y-1 transition-all duration-300">
          <div class="card-icon-container w-12 h-12 flex items-center justify-center rounded-xl mb-3 gradient-background-4 text-white shadow-md">
            <i class="ph-users-three text-2xl md:text-3xl"></i>
          </div>
          <h2 class="card-title text-base md:text-lg font-semibold text-slate-700 mb-1">Reminder Grup</h2>
          <p class="text-xs md:text-sm text-slate-500">Kirim pesan ke grup WA.</p>
        </a>
      </div>
    </main>
    <footer class="text-center py-4 text-slate-500 text-sm">
        Powered by Farhan Ramadhan
    </footer>
  </div>
</body>
</html>