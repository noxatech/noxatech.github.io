const express = require('express');
const multer = require('multer');
const path = require('path');
const fs = require('fs').promises;
require('dotenv').config();
const fetch = global.fetch || require('node-fetch');

const app = express();
const upload = multer({ dest: path.join(__dirname, 'uploads/') });

app.use(express.json());
// Statik olarak uploads klasörünü servis et (ngrok veya sunucu public yapıldığında RapidAPI bu URL'yi görebilir)
app.use('/uploads', express.static(path.join(__dirname, 'uploads')));

app.post('/api/analyze', upload.single('image'), async (req, res) => {
  if (!req.file) return res.status(400).json({ error: 'Dosya bulunamadı' });

  const fileUrl = `${req.protocol}://${req.get('host')}/uploads/${req.file.filename}`;
  // RapidAPI ayarları - KEY'i .env dosyasına koyun: RAPIDAPI_KEY=xxxxx
  const url = 'https://chatgpt-vision1.p.rapidapi.com/matagvision2';
  const apiKey = process.env.RAPIDAPI_KEY;
  if (!apiKey) {
    // Temizlik: dosyayı hemen sil
    await fs.unlink(req.file.path).catch(()=>{});
    return res.status(500).json({ error: 'RAPIDAPI_KEY çevre değişkeni ayarlı değil' });
  }

  // Gövdeyi gerektiği şekilde düzenleyin; örnek format kullanıcı tarafından verilene göre hazırlandı
  const body = {
    messages: [
      {
        role: 'user',
        content: [
          { type: 'text', text: 'Lütfen bu fotoğraftaki ağacı tespit et: isim, yaygın bölgeler, optimum yaşam koşulları, tahmini gövde yarıçapı ve güven skoru.' },
          { type: 'image', url: fileUrl }
        ]
      }
    ],
    web_access: false
  };

  try {
    const response = await fetch(url, {
      method: 'POST',
      headers: {
        'x-rapidapi-key': apiKey,
        'x-rapidapi-host': 'chatgpt-vision1.p.rapidapi.com',
        'Content-Type': 'application/json'
      },
      body: JSON.stringify(body)
    });

    const apiResultText = await response.text(); // API'nin JSON veya düz metin dönebileceğini varsayıyoruz
    // API yanıtı geldikten sonra dosyayı hemen sil (en basit ve güvenli yöntem)
    await fs.unlink(req.file.path).catch(()=>{});

    // İsteğe bağlı: 30 dakika sonra silme yerine zaman odaklı temizleme kullanmak isterseniz:
    // setTimeout(()=> fs.unlink(req.file.path).catch(()=>{}), 30 * 60 * 1000);

    // Front-end'e ham yanıtı gönder (isterseniz burada parse edip temiz JSON dönebilirsiniz)
    res.setHeader('Content-Type', 'application/json');
    // Eğer apiResultText zaten JSON string ise direkt iletebiliriz, değilse sar
    try {
      const parsed = JSON.parse(apiResultText);
      return res.json(parsed);
    } catch {
      return res.json({ raw: apiResultText });
    }
  } catch (err) {
    // Hata durumunda dosyayı silmeyi dene
    await fs.unlink(req.file.path).catch(()=>{});
    console.error(err);
    res.status(500).json({ error: 'API çağrısı sırasında hata oluştu' });
  }
});

const PORT = process.env.PORT || 3000;
app.listen(PORT, ()=> console.log(`Server ${PORT} portunda çalışıyor`));