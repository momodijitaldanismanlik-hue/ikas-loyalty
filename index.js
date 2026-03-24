require("dotenv").config();
const express = require("express");
const { Pool } = require("pg");
const axios = require("axios");
const cron = require("node-cron");

const app = express();
app.use(express.json());

const PORT = process.env.PORT || 3000;
const EARN_RATE_PER_100 = 5;

const pool = new Pool({
  connectionString: process.env.DATABASE_URL,
  ssl: { rejectUnauthorized: false },
});

function calculatePoints(orderTotal) {
  return Math.floor(Number(orderTotal || 0) / 100) * EARN_RATE_PER_100;
}

async function getIkasToken() {
  const url = `https://${process.env.IKAS_STORE}.myikas.com/api/admin/oauth/token`;

  const body = new URLSearchParams({
    grant_type: "client_credentials",
    client_id: process.env.IKAS_CLIENT_ID,
    client_secret: process.env.IKAS_CLIENT_SECRET,
  });

  const res = await axios.post(url, body.toString(), {
    headers: {
      "Content-Type": "application/x-www-form-urlencoded",
    },
  });

  return res.data.access_token;
}

async function ikasQuery(query) {
  const token = await getIkasToken();

  const res = await axios.post(
    "https://api.myikas.com/api/v1/admin/graphql",
    { query },
    {
      headers: {
        Authorization: `Bearer ${token}`,
      },
    }
  );

  return res.data;
}

async function ensureWallet(customerId) {
  await pool.query(
    "insert into loyalty_wallets (customer_id) values ($1) on conflict do nothing",
    [customerId]
  );
}

async function syncOrders() {
  const data = await ikasQuery(`
    {
      listOrder {
        data {
          id
          totalPrice
          customer {
            id
            email
            firstName
            lastName
          }
          orderedAt
          orderNumber
          orderPaymentStatus
          status
        }
      }
    }
  `);

  const orders = data?.data?.listOrder?.data || [];

  let added = 0;
  let refunded = 0;
  let skipped = 0;

  for (const order of orders) {
    const customerId = order.customer?.id;
    const orderId = order.id;
    const total = Number(order.totalPrice || 0);
    const paymentStatus = order.orderPaymentStatus;
    const status = order.status;

    if (!customerId || !orderId) {
      skipped++;
      continue;
    }

    await ensureWallet(customerId);

    // İADE / İPTAL: daha önce EARN yazılmışsa geri al
    if (status === "REFUNDED" || status === "CANCELLED") {
      const refundExists = await pool.query(
        "select 1 from loyalty_transactions where order_id = $1 and type = 'REFUND' limit 1",
        [orderId]
      );

      if (refundExists.rows.length > 0) {
        skipped++;
        continue;
      }

      const earnTx = await pool.query(
        "select points from loyalty_transactions where order_id = $1 and type = 'EARN' limit 1",
        [orderId]
      );

      if (earnTx.rows.length === 0) {
        skipped++;
        continue;
      }

      const refundPoints = Number(earnTx.rows[0].points || 0);

      await pool.query(
        "update loyalty_wallets set points_balance = points_balance - $1, updated_at = now() where customer_id = $2",
        [refundPoints, customerId]
      );

      await pool.query(
        `insert into loyalty_transactions
        (customer_id, order_id, type, points, order_total, description)
        values ($1, $2, 'REFUND', $3, $4, $5)`,
        [customerId, orderId, refundPoints, total, "İade/iptal nedeniyle puan geri alındı"]
      );

      refunded++;
      console.log("🔻 Puan geri alındı:", orderId);
      continue;
    }

    // sadece ödenmiş siparişlere puan ver
    if (paymentStatus !== "PAID") {
      skipped++;
      continue;
    }

    // daha önce işlendi mi?
    const exists = await pool.query(
      "select 1 from orders_sync where order_id = $1 limit 1",
      [orderId]
    );

    if (exists.rows.length > 0) {
      skipped++;
      continue;
    }

    const points = calculatePoints(total);

    await pool.query(
      "update loyalty_wallets set points_balance = points_balance + $1, updated_at = now() where customer_id = $2",
      [points, customerId]
    );

    await pool.query(
      `insert into loyalty_transactions
      (customer_id, order_id, type, points, order_total, description)
      values ($1, $2, 'EARN', $3, $4, $5)`,
      [customerId, orderId, points, total, "ikas sipariş puanı"]
    );

    await pool.query(
      "insert into orders_sync (order_id) values ($1)",
      [orderId]
    );

    added++;
    console.log("✅ Puan yazıldı:", orderId);
  }

  return {
    ok: true,
    totalOrders: orders.length,
    added,
    refunded,
    skipped,
  };
}

app.get("/", async (req, res) => {
  try {
    const result = await pool.query("select now()");
    res.json({
      ok: true,
      message: "Database connected",
      time: result.rows[0].now,
    });
  } catch (error) {
    res.status(500).json({
      ok: false,
      error: error.message,
    });
  }
});

app.get("/ikas-test", async (req, res) => {
  try {
    const data = await ikasQuery(`
      query {
        me {
          id
        }
      }
    `);

    res.json(data);
  } catch (e) {
    res.status(500).json({
      error: e.message,
      details: e.response?.data || null,
    });
  }
});

app.get("/ikas-orders", async (req, res) => {
  try {
    const data = await ikasQuery(`
      {
        listOrder {
          data {
            id
            totalPrice
            customer {
              id
              email
              firstName
              lastName
            }
            orderedAt
            orderNumber
            orderPaymentStatus
            status
          }
        }
      }
    `);

    res.json(data);
  } catch (e) {
    res.status(500).json({
      error: e.message,
      details: e.response?.data || null,
    });
  }
});

app.get("/loyalty/:customerId", async (req, res) => {
  const { customerId } = req.params;

  try {
    let wallet = await pool.query(
      "select * from loyalty_wallets where customer_id = $1",
      [customerId]
    );

    if (wallet.rows.length === 0) {
      await pool.query(
        "insert into loyalty_wallets (customer_id) values ($1)",
        [customerId]
      );

      wallet = await pool.query(
        "select * from loyalty_wallets where customer_id = $1",
        [customerId]
      );
    }

    res.json(wallet.rows[0]);
  } catch (e) {
    res.status(500).json({ error: e.message });
  }
});

app.get("/loyalty-transactions/:customerId", async (req, res) => {
  const { customerId } = req.params;

  try {
    const wallet = await pool.query(
      "select * from loyalty_wallets where customer_id = $1",
      [customerId]
    );

    const transactions = await pool.query(
      `select * from loyalty_transactions
       where customer_id = $1
       order by created_at desc`,
      [customerId]
    );

    res.json({
      wallet: wallet.rows[0] || null,
      transactions: transactions.rows,
    });
  } catch (e) {
    res.status(500).json({ error: e.message });
  }
});

app.post("/earn", async (req, res) => {
  const { customerId, orderId, orderTotal } = req.body;

  try {
    if (!customerId || !orderTotal) {
      return res.status(400).json({ error: "Eksik veri" });
    }

    const points = calculatePoints(orderTotal);

    await ensureWallet(customerId);

    await pool.query(
      "update loyalty_wallets set points_balance = points_balance + $1, updated_at = now() where customer_id = $2",
      [points, customerId]
    );

    await pool.query(
      `insert into loyalty_transactions
       (customer_id, order_id, type, points, order_total, description)
       values ($1, $2, 'EARN', $3, $4, $5)`,
      [customerId, orderId || null, points, orderTotal, "Manuel sipariş puanı"]
    );

    res.json({
      ok: true,
      earnedPoints: points,
    });
  } catch (e) {
    res.status(500).json({ error: e.message });
  }
});

app.get("/sync-orders", async (req, res) => {
  try {
    const result = await syncOrders();
    res.json(result);
  } catch (e) {
    res.status(500).json({
      error: e.message,
      details: e.response?.data || null,
    });
  }
});

cron.schedule("*/5 * * * *", async () => {
  console.log("⏳ Sync çalışıyor...");

  try {
    const result = await syncOrders();
    console.log("✅ Sync tamamlandı:", result);
  } catch (e) {
    console.error("❌ Sync hata:", e.message);
  }
});

app.listen(PORT, () => {
  console.log(`Server running on http://localhost:${PORT}`);
});

function requireAdminSecret(req, res, next) {
  const key = req.query.key || req.headers["x-admin-key"];

  if (!process.env.ADMIN_SECRET) {
    return res.status(500).json({ error: "ADMIN_SECRET tanımlı değil" });
  }

  if (key !== process.env.ADMIN_SECRET) {
    return res.status(403).json({ error: "Yetkisiz erişim" });
  }

  next();
}
