# AI Mail Reply Asistant

Bu sistem, belirli bir e-posta adresine (örn. ai@example.com) gelen e-postaları otomatik olarak işleyerek, OpenAI API kullanarak yanıtlar oluşturur ve bu yanıtları tüm alıcılara (TO ve CC) gönderir.

## Özellikler

- IMAP ile okunmamış e-postaları okuma
- OpenAI API ile e-posta içeriğini analiz etme
- SMTP ile yanıtları gönderme
- Reply-all yaparak tüm alıcılara yanıt gönderme
- Sadece izin verilen domainlerden gelen e-postaları işleme
- Günlük istek limiti uygulama (her e-posta için 24 saatte 5 istek)
- Belirli sayıdan fazla alıcısı olan e-postaları işlememe (varsayılan: 10)
- Engellenen alıcı listesindeki e-posta adreslerine gönderilen e-postaları işlememe
- Engellenen gönderen listesindeki e-posta adreslerinden gelen e-postaları işlememe
- İzin verilmeyen domainlerden gelen e-postaları otomatik olarak silme
- Engellenen gönderenlerden gelen e-postaları otomatik olarak silme
- Özel direktifleri işleme (belirli kişilere gönderme, ekleme/çıkarma vb.)
- Hata durumlarında bilgilendirme e-postası gönderme
- Markdown formatında yanıtlar oluşturma ve HTML'e dönüştürme
- E-posta içeriğindeki özel karakterleri doğru şekilde işleme
- E-posta zincirindeki tüm e-postaları doğru şekilde alıp işleme
- Emojileri e-posta içeriğinde doğru şekilde görüntüleme
- Eski e-posta zincirini yanıta ekleme özelliğini yapılandırabilme (opsiyonel)
- Hata ayıklama modunu etkinleştirme/devre dışı bırakma özelliği
- TO/CC alanı kontrolü: Sadece TO alanında olduğunda yanıt verme, CC olduğunda yanıt vermeme ve silme (opsiyonel)

## Kurulum

1. Repoyu klonlayın
2. Composer bağımlılıklarını yükleyin:
   ```
   composer install
   ```
3. `.env.example` dosyasını `.env` olarak kopyalayın ve gerekli bilgileri doldurun:
   ```
   cp .env.example .env
   ```
4. E-posta ve OpenAI API bilgilerinizi `.env` dosyasında güncelleyin
5. İsteğe bağlı olarak aşağıdaki ayarları yapılandırın:
   - `MAX_RECIPIENTS`: Maksimum alıcı sayısı (varsayılan: 10)
   - `BLOCKED_RECIPIENTS`: Engellenen alıcı e-posta adresleri (virgülle ayrılmış liste)
   - `BLOCKED_SENDERS`: Engellenen gönderen e-posta adresleri (virgülle ayrılmış liste)
   - `ALLOW_AI_RECIPIENTS`: AI'ın önerdiği alıcıların kullanılmasına izin ver (true/false, varsayılan: false)
   - `DEBUG`: Hata ayıklama modunu etkinleştir (true/false, varsayılan: true)
   - `INCLUDE_THREAD_EMAILS`: Eski e-posta zincirini yanıta ekleme (true/false, varsayılan: false)
    - `IGNORE_CC_EMAILS`: Sadece TO alanında olduğunda yanıt ver, CC olduğunda yanıt verme ve sil (true/false, varsayılan: false)

## Kullanım

Sistemi çalıştırmak için:

```
php public/index.php
```

Bu komut, IMAP sunucusundan okunmamış e-postaları alacak, OpenAI API ile işleyecek ve yanıtları gönderecektir.

## Cron Job Kurulumu

Sistemi düzenli olarak çalıştırmak için bir cron job ekleyebilirsiniz:

```
*/5 * * * * cd /path/to/project && php public/index.php >> /dev/null 2>&1
```

Bu, sistemi her 5 dakikada bir çalıştıracaktır.

## Özel Direktifler

Kullanıcılar, e-postalarında özel direktifler kullanabilirler:

1. **Belirli bir kişiye yanıt gönderme**:
   ```
   Cevabı sadece example@example.com'a gönder
   ```

2. **Ek alıcı ekleme**:
   ```
   Şunu da ekle: user@example.com
   ```

3. **Özel prompt kullanma**:
   E-postanın ilk satırı özel prompt olarak kullanılır.

## JSON Formatında Direktifler

AI yanıtları, JSON formatında özel direktifler içerebilir:

```json
{
  "recipients": ["ornek@example.com"],
  "cc": ["kopya@example.com"],
  "only_to_these_recipients": true
}
```

Bu JSON direktiflerinin işlenmesi, `.env` dosyasındaki ayarlarla kontrol edilir:

- `ALLOW_AI_RECIPIENTS=true`: AI'ın önerdiği alıcılar kullanılır
- `ALLOW_AI_RECIPIENTS=false`: AI'ın önerdiği alıcılar kullanılmaz (varsayılan)

Bu ayarlar, güvenlik nedeniyle varsayılan olarak devre dışıdır. AI'ın önerdiği alıcıların kullanılmasına izin vermek, potansiyel olarak istenmeyen alıcılara e-posta gönderilmesine neden olabilir.

## Hata Ayıklama Modu

Sistem, hata ayıklama modunu `.env` dosyasındaki `DEBUG` ayarı ile kontrol eder:

- `DEBUG=true`: Tüm log mesajları (INFO, WARNING, ERROR) app.log dosyasına yazılır ve terminal çıktıları gösterilir (varsayılan)
- `DEBUG=false`: Sadece hata mesajları (ERROR) app.log dosyasına yazılır ve sadece hata mesajları terminalde gösterilir

Hata ayıklama modu devre dışı bırakıldığında, sistem sessiz modda çalışır ve sadece hata durumlarında bilgi verir.

## Markdown Desteği

Sistem, OpenAI'dan gelen yanıtları Markdown formatında alır ve e-posta gönderilmeden önce HTML'e dönüştürür. Bu sayede:

- Kalın metinler (**kalın**) → <strong>kalın</strong>
- İtalik metinler (*italik*) → <em>italik</em>
- Altı çizili metinler (__altı çizili__) → <u>altı çizili</u>
- Kod blokları (`kod`) → <code>kod</code>
- Emojiler (😊 👍 🎉) → Doğrudan görüntülenir

## E-posta Zinciri Yönetimi

Sistem, e-posta zincirindeki tüm e-postaları doğru şekilde alıp işleyebilir. `.env` dosyasındaki `INCLUDE_THREAD_EMAILS` ayarı ile, eski e-posta zincirinin yanıta eklenip eklenmeyeceğini belirleyebilirsiniz:

- `INCLUDE_THREAD_EMAILS=true`: Eski e-posta zinciri yanıta eklenir
- `INCLUDE_THREAD_EMAILS=false`: Eski e-posta zinciri yanıta eklenmez (varsayılan)

## TO/CC Alanı Kontrolü

Sistem, e-postanın TO veya CC alanında olup olmadığını kontrol edebilir ve buna göre işlem yapabilir:

- `IGNORE_CC_EMAILS=true`: Sistem sadece TO alanında olduğunda yanıt verir, CC alanında olduğunda yanıt vermez ve e-postayı siler
- `IGNORE_CC_EMAILS=false`: Sistem hem TO hem de CC alanında olduğunda yanıt verir (varsayılan)

Bu özellik, AI'ın sadece kendisine doğrudan gönderilen e-postalara yanıt vermesini sağlar ve bilgi amaçlı CC olarak eklendiği e-postaları otomatik olarak siler.

## Engellenen Gönderenler ve Alıcılar

Sistem, engellenen gönderenlerden gelen e-postaları ve engellenen alıcılara gönderilen e-postaları işlemez:

- Engellenen gönderenlerden gelen e-postalar otomatik olarak silinir
- Engellenen alıcılara gönderilen e-postalar işlenmez

Engellenen gönderenler ve alıcılar, `.env` dosyasında yapılandırılabilir:

```
BLOCKED_RECIPIENTS=spam@example.com,unwanted@example.com
BLOCKED_SENDERS=spammer@example.com,blacklisted@example.com
```

## Güvenlik

- Sistem, sadece izin verilen domainlerden gelen e-postaları işler
- Yanıtlar, sadece orijinal e-postadaki alıcılara veya izin verilen domainlere gönderilir
- Günlük istek limiti, aşırı kullanımı önler
- Belirli sayıdan fazla alıcısı olan e-postalar işlenmez
- Engellenen alıcı listesindeki e-posta adreslerine gönderilen e-postalar işlenmez
- Engellenen gönderen listesindeki e-posta adreslerinden gelen e-postalar otomatik olarak silinir
- İzin verilmeyen domainlerden gelen e-postalar otomatik olarak silinir

## Hata Yönetimi

Sistem, çeşitli hata durumlarında bilgilendirme e-postaları gönderir:

- Günlük istek limiti aşıldığında
- E-posta işlenirken hata oluştuğunda
- E-posta yanıtı gönderilemediğinde

## Gereksinimler

- PHP 8.2 veya üzeri
- IMAP PHP eklentisi
- OpenAI API anahtarı
- SMTP erişimi olan bir e-posta hesabı
- Composer (bağımlılıkları yönetmek için)

## Bağımlılıklar

- PHPMailer: E-posta gönderimi için
- Monolog: Loglama için
- GuzzleHTTP: OpenAI API istekleri için
- Dotenv: Çevre değişkenlerini yönetmek için
- Parsedown: Markdown'ı HTML'e dönüştürmek için