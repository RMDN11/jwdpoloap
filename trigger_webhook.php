<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trigger Webhook Manual - Jawwada TP</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f4f4;
            padding: 20px;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        h1 {
            text-align: center;
            color: #333;
        }
        .form-group {
            margin-bottom: 15px;
        }
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        input[type="text"],
        input[type="number"],
        textarea {
            width: 100%;
            padding: 8px;
            border: 1px solid #ccc;
            border-radius: 4px;
            box-sizing: border-box;
        }
        textarea {
            height: 100px;
            resize: vertical;
        }
        button {
            background-color: #4CAF50;
            color: white;
            padding: 12px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            width: 100%;
            font-size: 16px;
        }
        button:hover {
            background-color: #45a049;
        }
        .response {
            margin-top: 20px;
            padding: 15px;
            border-radius: 4px;
        }
        .success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
    </style>
</head>
<body>

<div class="container">
    <h1>Trigger Webhook Manual</h1>
    <p style="text-align: center; color: #666; font-style: italic;">Gunakan untuk menguji webhook dan mencatat percakapan manual.</p>
    <form id="webhookForm">
        <div class="form-group">
            <label for="from">Nomor Pengirim (format internasional, e.g., 6281234567890):</label>
            <input type="text" id="from" name="from" required placeholder="6281234567890">
        </div>

        <div class="form-group">
            <label for="name">Nama Pengirim (Opsional):</label>
            <input type="text" id="name" name="name" placeholder="Nama atau Alias">
        </div>

        <div class="form-group">
            <label for="message">Isi Pesan:</label>
            <textarea id="message" name="message" required placeholder="Tulis pesan di sini..."></textarea>
        </div>

        <div class="form-group">
            <label for="type">Tipe Pesan (Opsional):</label>
            <input type="text" id="type" name="type" value="text" placeholder="text, image, etc. (default: text)">
        </div>

        <button type="submit">Kirim ke Webhook</button>
    </form>

    <div id="responseContainer" class="response" style="display: none;"></div>
</div>

<script>
document.getElementById('webhookForm').addEventListener('submit', async function(e) {
    e.preventDefault(); // Mencegah submit form default

    const formData = {
        from: document.getElementById('from').value.trim(),
        name: document.getElementById('name').value.trim(),
        message: document.getElementById('message').value.trim(),
        type: document.getElementById('type').value.trim() || 'text' // Gunakan 'text' jika kosong
    };

    // Validasi sederhana
    if (!formData.from || !formData.message) {
        alert('Nomor Pengirim dan Isi Pesan wajib diisi.');
        return;
    }

    // API Key Anda
    const apiKey = 'ac533611-d3a6-4c6a-b6ca-0fe3925b0fdb'; // Ganti jika berbeda

    try {
        const response = await fetch('webhook.php', { // Ganti dengan URL webhook.php Anda jika berbeda
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'jwdtp': apiKey // Nama header harus sesuai dengan yang diperiksa di webhook.php
            },
            body: JSON.stringify(formData)
        });

        const result = await response.json();
        const responseContainer = document.getElementById('responseContainer');

        if (response.ok) {
            responseContainer.className = 'response success';
            responseContainer.innerHTML = `<h3>Success!</h3><pre>${JSON.stringify(result, null, 2)}</pre>`;
        } else {
            responseContainer.className = 'response error';
            responseContainer.innerHTML = `<h3>Error ${response.status}!</h3><pre>${JSON.stringify(result, null, 2)}</pre>`;
        }
        responseContainer.style.display = 'block';

    } catch (error) {
        console.error('Error:', error);
        const responseContainer = document.getElementById('responseContainer');
        responseContainer.className = 'response error';
        responseContainer.innerHTML = `<h3>Error Jaringan!</h3><p>${error.message}</p>`;
        responseContainer.style.display = 'block';
    }
});
</script>

</body>
</html>