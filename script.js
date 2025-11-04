// AOS Animasyon Başlatma
document.addEventListener('DOMContentLoaded', function() {
    AOS.init();
});

let selectedFile = null;
const imageInput = document.getElementById('imageUpload');
const analyzeBtn = document.getElementById('analyzeBtn');
const resultDiv = document.getElementById('result');
const previewWrap = document.getElementById('previewWrap');
const uploadStatus = document.getElementById('uploadStatus');

imageInput.addEventListener('change', function(e) {
    selectedFile = e.target.files[0];
    resultDiv.innerHTML = '';
    if (!selectedFile) {
        previewWrap.innerHTML = '';
        analyzeBtn.style.display = 'none';
        uploadStatus.textContent = '';
        return;
    }

    // Önizleme ve butonu göster
    const previewUrl = URL.createObjectURL(selectedFile);
    previewWrap.innerHTML = `<img src="${previewUrl}" alt="preview" class="img-fluid mb-2" style="max-height:250px;">`;
    uploadStatus.textContent = 'Fotoğraf yüklendi. "Analiz Et" butonuna basın.';
    analyzeBtn.style.display = 'inline-block';
    analyzeBtn.disabled = false;
});

analyzeBtn.addEventListener('click', async function() {
    if (!selectedFile) return;
    
    console.log('Analiz başlatılıyor...');
    console.log('Seçilen dosya:', selectedFile.name);
    
    analyzeBtn.disabled = true;
    uploadStatus.textContent = 'Analiz yapılıyor, lütfen bekleyin...';
    resultDiv.innerHTML = '';

    const formData = new FormData();
    formData.append('image', selectedFile);

    try {
        console.log('Server.php\'ye istek gönderiliyor...');
        const response = await fetch('/server.php', {
            method: 'POST',
            body: formData
        });

        console.log('Server yanıtı alındı:', response.status);
        const contentType = response.headers.get('content-type') || '';
        console.log('Content-Type:', contentType);

        const result = await response.text(); // Önce text olarak alalım
        console.log('Ham yanıt:', result);

        let parsedResult;
        try {
            parsedResult = JSON.parse(result);
            console.log('Parse edilmiş yanıt:', parsedResult);
        } catch (e) {
            console.error('JSON parse hatası:', e);
            resultDiv.innerHTML = `<div class="alert alert-danger">API yanıtı JSON formatında değil: ${result}</div>`;
            return;
        }

        if (parsedResult.error) {
            console.error('API hata döndü:', parsedResult.error);
            resultDiv.innerHTML = `<div class="alert alert-danger">Hata: ${parsedResult.error}</div>`;
            return;
        }

        // RapidAPI yanıtı genelde choices içinde geliyor
        const apiResponse = parsedResult.choices?.[0]?.message?.content || parsedResult;
        console.log('İşlenecek API yanıtı:', apiResponse);

        displayResult(apiResponse);
        uploadStatus.textContent = 'Analiz tamamlandı.';

    } catch (error) {
        console.error('Fetch hatası:', error);
        uploadStatus.textContent = 'Analiz sırasında hata oluştu.';
        resultDiv.innerHTML = `<div class="alert alert-danger">Hata: ${error.message}</div>`;
    } finally {
        analyzeBtn.disabled = false;
    }
});

function displayResult(data) {
    console.log('DisplayResult çağrıldı, veri:', data);

    if (!data.status) {
        resultDiv.innerHTML = `
            <div class="alert alert-danger">
                ${data.error || 'Analiz sırasında bir hata oluştu'}
            </div>
        `;
        return;
    }

    const analysisData = data.data || {};
    
    resultDiv.innerHTML = `
        <div class="card">
            <div class="card-body">
                <h4 class="card-title">Analiz Sonuçları</h4>
                <p><strong>Ağaç Türü:</strong> ${analysisData.treeName || 'Belirlenemedi'}</p>
                <p><strong>Yaygın Bölgeler:</strong> ${analysisData.regions || 'Belirlenemedi'}</p>
                <p><strong>Büyüme Koşulları:</strong> ${analysisData.conditions || 'Belirlenemedi'}</p>
                <p><strong>Gövde Yarıçapı:</strong> ${analysisData.radius || 'Belirlenemedi'}</p>
                <p><strong>Tahmini Yaş:</strong> ${analysisData.age || 'Belirlenemedi'}</p>
                <p><strong>Güven Skoru:</strong> ${analysisData.confidence || '0%'}</p>
                ${analysisData.rawResponse ? `<hr><pre class="mt-3">${analysisData.rawResponse}</pre>` : ''}
            </div>
        </div>
    `;
}