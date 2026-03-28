# 🧠 IKAS Loyalty System – Full Documentation

## 📌 Proje Amacı

IKAS altyapılı e-ticaret sitesi için:

- Müşteri bazlı sadakat puan sistemi
- Siparişe göre otomatik puan kazanımı
- İade/iptal durumunda puan geri alma
- API üzerinden puan görüntüleme
- Production ortamında çalışan backend sistemi

---

# ⚙️ Kullanılan Teknolojiler

- Node.js (Express)
- PostgreSQL (Supabase)
- IKAS Admin API (GraphQL)
- Render (deployment)
- node-cron (otomatik sync)
- GitHub (versiyon kontrol)

---

# 🏗️ Sistem Mimarisi

IKAS → Backend API → Supabase DB
↓
Loyalty Engine
↓
Frontend (script)

---

# 🗄️ Veritabanı Yapısı

## loyalty_wallets

- customer_id
- points_balance
- updated_at

## loyalty_transactions

- customer_id
- order_id
- type (EARN / REFUND)
- points
- order_total
- description
- created_at

## orders_sync

- order_id (duplicate engelleme)

---

# 🔌 API Endpointleri

## 1. Database Test

GET /

---

## 2. IKAS Bağlantı Testi

GET /ikas-test

---

## 3. Sipariş Listeleme

GET /ikas-orders

---

## 4. Puan Senkronizasyonu

GET /sync-orders

Güvenli kullanım:
GET /sync-orders?key=SECRET_KEY

---

## 5. Müşteri Puanı

GET /loyalty/{customer_id}

---

## 6. İşlem Geçmişi

GET /loyalty-transactions/{customer_id}

---

## 7. Manuel Puan Ekleme

POST /earn

---

# 🔁 Cron Sistemi

cron.schedule("_/5 _ \* \* \*", async () => {
await syncOrders();
});

✔ Her 5 dakikada çalışır  
✔ Yeni siparişleri işler

---

# 🎯 Puan Hesaplama

points = Math.floor(orderTotal / 100) \* 5;

Örnek:

- 649 TL → 30 puan

---

# 💰 Puan Kazanım Kuralları

✔ Sadece ödeme alınmış siparişler:

orderPaymentStatus === "PAID"

---

# 🔻 İade / İptal Sistemi

const isRefunded =
status === "REFUNDED" ||
status === "CANCELLED" ||
status === "PARTIALLY_REFUNDED" ||
paymentStatus === "REFUNDED";

İşlem:

- önce EARN bulunur
- aynı sipariş için REFUND yoksa:
  - puan düşülür
  - REFUND transaction yazılır

---

# 🔐 Endpoint Güvenliği

function requireAdminSecret(req, res, next) {
const key = req.query.key;

if (key !== process.env.ADMIN_SECRET) {
return res.status(403).json({ error: "Yetkisiz erişim" });
}

next();
}

---

# 🌐 Deployment (Render)

## Ayarlar

- Runtime: Node
- Build Command:

npm install

- Start Command:

node index.js

---

## Environment Variables

DATABASE_URL=postgresql://...
IKAS_STORE=gizemakardesign
IKAS_CLIENT_ID=...
IKAS_CLIENT_SECRET=...
PORT=3000
ADMIN_SECRET=...

---

# ⚠️ Kritik Hatalar ve Çözümler

## 1. DATABASE_URL hatası

Şifre encode edilmelidir:

! → %21

→ %23

---

## 2. Yanlış IKAS_STORE

❌ gizemakardesign.com  
✅ gizemakardesign

---

## 3. Duplicate puan sorunu

✔ orders_sync tablosu ile çözüldü

---

## 4. İade çalışmıyor

✔ IKAS API status değişmeden tetiklenmez

---

# 🧪 Test Senaryoları

- yeni sipariş
- duplicate sync
- bekleyen ödeme
- ödeme sonrası
- iptal
- iade
- multi order
- customer yok

---

# 🎨 Frontend Entegrasyonu

Script ile puan çekme:

fetch("/loyalty/{customerId}")

Gösterim:

Sadakat Puanınız: 60

---

# 💳 Puan Kullanımı (Önerilen Model)

## Kupon Tabanlı Sistem

Akış:

1. kullanıcı puan kullanır
2. backend:
   - puanı hesaplar
   - kupon üretir
3. kullanıcı checkout'ta kullanır
4. sipariş sonrası:
   - puan düşülür
5. iptal/iade:
   - puan geri yüklenir

---

# 📦 IKAS Kupon Sistemi (GraphQL)

mutation {
createCampaign(input: {
title: "Loyalty Discount",
discountType: FIXED_AMOUNT,
discountValue: 50,
code: "LOYALTY123"
}) {
id
}
}

---

# 🔐 Güvenlik Notları

⚠️ Secret paylaşıldıysa:

- IKAS client_secret yenilenmeli
- Supabase şifre resetlenmeli
- Render env güncellenmeli

---

# 🚀 Sistem Durumu

✔ Production hazır  
✔ Otomatik çalışıyor  
✔ Gerçek sipariş test edildi  
✔ İade sistemi çalışıyor  
✔ Güvenlik eklendi

---

# 🎯 Sonraki Geliştirme Alanları

- müşteri panel UI
- admin dashboard
- puan expiry sistemi
- kampanya yönetimi
- webhook ile anlık sync
- SaaS ürünleştirme

---

# 🧠 Genel Özet

Bu sistem:

✔ IKAS ile entegre  
✔ otomatik puan motoru  
✔ iade yönetimi var  
✔ production seviyesinde

👉 Artık **ürün olarak satılabilir seviyede**
