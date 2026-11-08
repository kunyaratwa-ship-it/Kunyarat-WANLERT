-- ============================================================
--  ระบบจัดการวัตถุดิบครัว — Kitchen Inventory Management
--  Database: kitchen_inventory
--  Engine  : MySQL / MariaDB (XAMPP compatible)
--  Version : 1.0  |  มีนาคม 2569
-- ============================================================

SET NAMES utf8mb4;
SET CHARACTER SET utf8mb4;
SET time_zone = '+07:00';

-- ─── สร้างและเลือก Database ─────────────────────────────────
DROP DATABASE IF EXISTS kitchen_inventory;
CREATE DATABASE kitchen_inventory
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE kitchen_inventory;

-- ============================================================
-- 1. users — ผู้ใช้งาน (พนักงานและผู้ดูแลระบบ)
-- ============================================================
CREATE TABLE users (
  user_id       INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  full_name     VARCHAR(150)     NOT NULL,
  email         VARCHAR(150)     NOT NULL,
  password_hash VARCHAR(255)     NOT NULL  COMMENT 'bcrypt hash; ตัวอย่างใช้ plain สำหรับ dev',
  phone         VARCHAR(20)          NULL  DEFAULT NULL,
  role          ENUM('staff','admin') NOT NULL DEFAULT 'staff',
  status        ENUM('active','pending','suspended') NOT NULL DEFAULT 'pending',
  created_at    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  UNIQUE  KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='ผู้ใช้งานระบบ';

-- ============================================================
-- 2. categories — หมวดหมู่วัตถุดิบ
-- ============================================================
CREATE TABLE categories (
  category_id   INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  name          VARCHAR(100)  NOT NULL,
  emoji         VARCHAR(10)   NOT NULL DEFAULT '📦',
  is_active     TINYINT(1)    NOT NULL DEFAULT 1,
  created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (category_id),
  UNIQUE KEY uq_cat_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='หมวดหมู่วัตถุดิบ';

-- ============================================================
-- 3. units — หน่วยนับ
-- ============================================================
CREATE TABLE units (
  unit_id       INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  name          VARCHAR(50)   NOT NULL,
  is_active     TINYINT(1)    NOT NULL DEFAULT 1,
  created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (unit_id),
  UNIQUE KEY uq_unit_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='หน่วยนับวัตถุดิบ';

-- ============================================================
-- 4. ingredients — วัตถุดิบ (ตารางหลัก)
-- ============================================================
CREATE TABLE ingredients (
  ingredient_id   INT UNSIGNED      NOT NULL AUTO_INCREMENT,
  code            VARCHAR(20)       NOT NULL,
  name            VARCHAR(200)      NOT NULL,
  category_id     INT UNSIGNED      NOT NULL,
  unit_id         INT UNSIGNED      NOT NULL,
  stock_qty       DECIMAL(12,3)     NOT NULL DEFAULT 0.000,
  price_per_unit  DECIMAL(10,2)     NOT NULL DEFAULT 0.00,
  expiry_date     DATE                  NULL DEFAULT NULL,
  image_url       VARCHAR(500)          NULL DEFAULT NULL  COMMENT 'URL หรือ Emoji',
  status          ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_by      INT UNSIGNED          NULL DEFAULT NULL,
  created_at      DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (ingredient_id),
  UNIQUE  KEY uq_ing_code  (code),
  KEY     idx_ing_cat      (category_id),
  KEY     idx_ing_unit     (unit_id),
  KEY     idx_ing_status   (status),
  KEY     idx_ing_expiry   (expiry_date),
  CONSTRAINT fk_ing_cat    FOREIGN KEY (category_id) REFERENCES categories (category_id),
  CONSTRAINT fk_ing_unit   FOREIGN KEY (unit_id)     REFERENCES units      (unit_id),
  CONSTRAINT fk_ing_user   FOREIGN KEY (created_by)  REFERENCES users      (user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='ข้อมูลหลักวัตถุดิบ';

-- ============================================================
-- 5. transactions — รายการรับเข้า / เบิกออก
-- ============================================================
CREATE TABLE transactions (
  txn_id          INT UNSIGNED      NOT NULL AUTO_INCREMENT,
  txn_type        ENUM('receive','withdraw') NOT NULL,
  ingredient_id   INT UNSIGNED      NOT NULL,
  qty             DECIMAL(12,3)     NOT NULL,
  price_per_unit  DECIMAL(10,2)     NOT NULL DEFAULT 0.00,
  total_price     DECIMAL(12,2)     GENERATED ALWAYS AS (qty * price_per_unit) STORED,
  reason          VARCHAR(200)          NULL DEFAULT NULL,
  expiry_date     DATE                  NULL DEFAULT NULL  COMMENT 'วันหมดอายุของล็อตที่รับเข้า',
  note            VARCHAR(500)          NULL DEFAULT NULL,
  performed_by    INT UNSIGNED      NOT NULL,
  txn_date        DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (txn_id),
  KEY idx_txn_ing    (ingredient_id),
  KEY idx_txn_user   (performed_by),
  KEY idx_txn_date   (txn_date),
  KEY idx_txn_type   (txn_type),
  CONSTRAINT fk_txn_ing    FOREIGN KEY (ingredient_id) REFERENCES ingredients (ingredient_id),
  CONSTRAINT fk_txn_user   FOREIGN KEY (performed_by)  REFERENCES users       (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='ประวัติรับเข้า-เบิกออก';

-- ============================================================
-- 6. orders — คำสั่งซื้อ
-- ============================================================
CREATE TABLE orders (
  order_id      INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  status        ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  requested_by  INT UNSIGNED  NOT NULL,
  approved_by   INT UNSIGNED      NULL DEFAULT NULL,
  note          VARCHAR(500)      NULL DEFAULT NULL,
  requested_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  approved_at   DATETIME          NULL DEFAULT NULL,
  PRIMARY KEY (order_id),
  KEY idx_ord_req    (requested_by),
  KEY idx_ord_appr   (approved_by),
  KEY idx_ord_status (status),
  CONSTRAINT fk_ord_req    FOREIGN KEY (requested_by) REFERENCES users (user_id),
  CONSTRAINT fk_ord_appr   FOREIGN KEY (approved_by)  REFERENCES users (user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='คำสั่งซื้อวัตถุดิบ';

-- ============================================================
-- 7. order_items — รายการในคำสั่งซื้อ
-- ============================================================
CREATE TABLE order_items (
  item_id         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  order_id        INT UNSIGNED  NOT NULL,
  ingredient_id   INT UNSIGNED  NOT NULL,
  qty             DECIMAL(12,3) NOT NULL,
  price_per_unit  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (item_id),
  KEY idx_oi_order (order_id),
  KEY idx_oi_ing   (ingredient_id),
  CONSTRAINT fk_oi_order  FOREIGN KEY (order_id)      REFERENCES orders      (order_id)      ON DELETE CASCADE,
  CONSTRAINT fk_oi_ing    FOREIGN KEY (ingredient_id) REFERENCES ingredients (ingredient_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='รายการวัตถุดิบในคำสั่งซื้อ';

-- ============================================================
-- 8. audit_logs — บันทึกการใช้งาน
-- ============================================================
CREATE TABLE audit_logs (
  log_id      INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  table_name  VARCHAR(100)  NOT NULL,
  record_id   INT UNSIGNED  NOT NULL,
  action      ENUM('INSERT','UPDATE','DELETE') NOT NULL,
  old_values  JSON              NULL DEFAULT NULL,
  new_values  JSON              NULL DEFAULT NULL,
  changed_by  INT UNSIGNED      NULL DEFAULT NULL,
  changed_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (log_id),
  KEY idx_log_table  (table_name),
  KEY idx_log_record (record_id),
  KEY idx_log_user   (changed_by),
  KEY idx_log_date   (changed_at),
  CONSTRAINT fk_log_user FOREIGN KEY (changed_by) REFERENCES users (user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='บันทึกการเปลี่ยนแปลงข้อมูล';

-- ============================================================
-- TRIGGERS — อัปเดตสต็อกอัตโนมัติ
-- ============================================================
DELIMITER $$

-- หลังบันทึก transaction ใหม่ → อัปเดต stock_qty
CREATE TRIGGER trg_after_txn_insert
AFTER INSERT ON transactions
FOR EACH ROW
BEGIN
  IF NEW.txn_type = 'receive' THEN
    UPDATE ingredients
    SET stock_qty  = stock_qty + NEW.qty,
        updated_at = NOW()
    WHERE ingredient_id = NEW.ingredient_id;
  ELSEIF NEW.txn_type = 'withdraw' THEN
    UPDATE ingredients
    SET stock_qty  = GREATEST(0, stock_qty - NEW.qty),
        updated_at = NOW()
    WHERE ingredient_id = NEW.ingredient_id;
  END IF;
END$$

-- หลัง approve order → บันทึก audit log
CREATE TRIGGER trg_after_order_update
AFTER UPDATE ON orders
FOR EACH ROW
BEGIN
  IF OLD.status != NEW.status THEN
    INSERT INTO audit_logs (table_name, record_id, action, old_values, new_values, changed_by)
    VALUES (
      'orders', NEW.order_id, 'UPDATE',
      JSON_OBJECT('status', OLD.status),
      JSON_OBJECT('status', NEW.status, 'approved_by', NEW.approved_by),
      NEW.approved_by
    );
  END IF;
END$$

DELIMITER ;

-- ============================================================
-- VIEWS — มุมมองที่ใช้บ่อย
-- ============================================================

-- วัตถุดิบพร้อมชื่อหมวดหมู่และหน่วยนับ
CREATE VIEW v_ingredients AS
SELECT
  i.ingredient_id,
  i.code,
  i.name,
  c.name          AS category_name,
  c.emoji         AS category_emoji,
  u.name          AS unit_name,
  i.stock_qty,
  i.price_per_unit,
  ROUND(i.stock_qty * i.price_per_unit, 2) AS total_value,
  i.expiry_date,
  DATEDIFF(i.expiry_date, CURDATE())       AS days_to_expiry,
  i.image_url,
  i.status,
  i.created_at,
  i.updated_at
FROM ingredients i
JOIN categories  c ON c.category_id = i.category_id
JOIN units       u ON u.unit_id     = i.unit_id;

-- รายงานรายการรับ-เบิก พร้อมชื่อผู้ทำรายการ
CREATE VIEW v_transactions AS
SELECT
  t.txn_id,
  t.txn_type,
  i.code              AS ingredient_code,
  i.name              AS ingredient_name,
  c.name              AS category_name,
  u.name              AS unit_name,
  t.qty,
  t.price_per_unit,
  t.total_price,
  t.reason,
  t.expiry_date,
  t.note,
  us.full_name        AS performed_by_name,
  t.txn_date
FROM transactions t
JOIN ingredients  i  ON i.ingredient_id = t.ingredient_id
JOIN categories   c  ON c.category_id   = i.category_id
JOIN units        u  ON u.unit_id       = i.unit_id
JOIN users        us ON us.user_id      = t.performed_by;

-- Dashboard: วัตถุดิบใกล้หมดและใกล้หมดอายุ
CREATE VIEW v_dashboard AS
SELECT
  ingredient_id,
  code,
  name,
  category_name,
  category_emoji,
  unit_name,
  stock_qty,
  price_per_unit,
  total_value,
  expiry_date,
  days_to_expiry,
  CASE
    WHEN stock_qty <= 2  THEN 'critical'
    WHEN stock_qty <= 5  THEN 'low'
    ELSE 'normal'
  END AS stock_status,
  CASE
    WHEN days_to_expiry <= 3  THEN 'critical'
    WHEN days_to_expiry <= 7  THEN 'warning'
    ELSE 'normal'
  END AS expiry_status
FROM v_ingredients
WHERE status = 'active';

-- ============================================================
-- STORED PROCEDURES
-- ============================================================
DELIMITER $$

-- รายงานสรุป: ต้นทุน + ความเสียหาย ตามช่วงเวลา
CREATE PROCEDURE sp_report_summary(
  IN p_from DATE,
  IN p_to   DATE
)
BEGIN
  SELECT
    SUM(CASE WHEN txn_type = 'receive'  THEN total_price ELSE 0 END) AS total_purchase_cost,
    SUM(CASE WHEN txn_type = 'withdraw' AND reason IN ('ของเสีย','ทิ้ง')
             THEN total_price ELSE 0 END)                             AS total_damage_cost,
    COUNT(*)                                                           AS total_transactions,
    SUM(CASE WHEN txn_type = 'receive'  THEN 1 ELSE 0 END)            AS receive_count,
    SUM(CASE WHEN txn_type = 'withdraw' THEN 1 ELSE 0 END)            AS withdraw_count
  FROM transactions
  WHERE DATE(txn_date) BETWEEN p_from AND p_to;
END$$

-- อนุมัติ order และสร้าง transaction รับเข้าอัตโนมัติ
CREATE PROCEDURE sp_approve_order(
  IN p_order_id   INT,
  IN p_admin_id   INT
)
BEGIN
  DECLARE done INT DEFAULT FALSE;
  DECLARE v_ing_id INT;
  DECLARE v_qty    DECIMAL(12,3);
  DECLARE v_price  DECIMAL(10,2);
  DECLARE cur CURSOR FOR
    SELECT ingredient_id, qty, price_per_unit FROM order_items WHERE order_id = p_order_id;
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = TRUE;

  START TRANSACTION;

  UPDATE orders
  SET status      = 'approved',
      approved_by = p_admin_id,
      approved_at = NOW()
  WHERE order_id = p_order_id AND status = 'pending';

  IF ROW_COUNT() = 0 THEN
    ROLLBACK;
    SELECT 'ORDER_NOT_FOUND_OR_ALREADY_PROCESSED' AS result;
  ELSE
    OPEN cur;
    read_loop: LOOP
      FETCH cur INTO v_ing_id, v_qty, v_price;
      IF done THEN LEAVE read_loop; END IF;
      INSERT INTO transactions (txn_type, ingredient_id, qty, price_per_unit, reason, performed_by)
      VALUES ('receive', v_ing_id, v_qty, v_price, 'สั่งซื้อ (อนุมัติ)', p_admin_id);
    END LOOP;
    CLOSE cur;
    COMMIT;
    SELECT 'SUCCESS' AS result;
  END IF;
END$$

DELIMITER ;

-- ============================================================
-- ตัวอย่างข้อมูล (SAMPLE DATA)
-- ============================================================

-- ─── users ───────────────────────────────────────────────────
INSERT INTO users (user_id, full_name, email, password_hash, phone, role, status) VALUES
(1, 'สมชาย ใจดี',    'staff@kitchen.com',  '1234',  '081-234-5678', 'staff', 'active'),
(2, 'มานี รักงาน',   'staff2@kitchen.com', '1234',  '082-345-6789', 'staff', 'pending'),
(3, 'วิภา สดใส',     'wipa@kitchen.com',   '1234',  '083-456-7890', 'staff', 'pending'),
(4, 'ประกาย กิจดี',  'prakay@kitchen.com', '1234',  '084-567-8901', 'staff', 'active'),
(5, 'Admin ร้าน',    'admin@kitchen.com',  'admin', '089-999-0000', 'admin', 'active');

-- ─── categories ──────────────────────────────────────────────
INSERT INTO categories (category_id, name, emoji) VALUES
(1, 'เนื้อสัตว์',  '🥩'),
(2, 'ผัก',         '🥬'),
(3, 'ผลไม้',       '🍎'),
(4, 'อาหารแห้ง',   '🌾'),
(5, 'เครื่องปรุง', '🧂'),
(6, 'เครื่องดื่ม', '🧃');

-- ─── units ───────────────────────────────────────────────────
INSERT INTO units (unit_id, name) VALUES
(1,  'กิโลกรัม'),
(2,  'ขีด'),
(3,  'กรัม'),
(4,  'ลูก'),
(5,  'หัว'),
(6,  'ผล'),
(7,  'ตัว'),
(8,  'แผ่น'),
(9,  'ก้อน'),
(10, 'ลิตร'),
(11, 'ใบ'),
(12, 'ฟอง'),
(13, 'แพ็ค'),
(14, 'ขวด'),
(15, 'โหล'),
(16, 'กระป๋อง'),
(17, 'ลัง'),
(18, 'ถัง');

-- ─── ingredients ─────────────────────────────────────────────
-- หมายเหตุ: stock_qty ถูก trigger จัดการ แต่ตั้งค่าตรงนี้สำหรับ initial stock
-- เนื่องจาก trigger ทำงานหลัง INSERT transactions เราจะ INSERT stock โดยตรง
INSERT INTO ingredients
  (ingredient_id, code, name, category_id, unit_id, stock_qty, price_per_unit, expiry_date, image_url, status, created_by)
VALUES
(1,  'ING001', 'เนื้อวัว',       1, 1,  15.000, 280.00, '2026-04-10', '🥩', 'active', 5),
(2,  'ING002', 'หมูสามชั้น',    1, 1,   8.000, 180.00, '2026-03-30', '🐷', 'active', 5),
(3,  'ING003', 'ไก่ทั้งตัว',     1, 7,   3.000, 120.00, '2026-03-28', '🐔', 'active', 5),
(4,  'ING004', 'กะหล่ำปลี',     2, 5,  20.000,  25.00, '2026-04-05', '🥬', 'active', 5),
(5,  'ING005', 'มะเขือเทศ',     2, 1,   2.000,  40.00, '2026-03-29', '🍅', 'active', 5),
(6,  'ING006', 'แครอท',          2, 1,  12.000,  35.00, '2026-04-15', '🥕', 'active', 5),
(7,  'ING007', 'มะม่วง',         3, 6,  25.000,  15.00, '2026-04-03', '🥭', 'active', 5),
(8,  'ING008', 'แตงโม',          3, 4,   5.000,  80.00, '2026-04-01', '🍉', 'active', 5),
(9,  'ING009', 'ข้าวสาร',        4, 1,  50.000,  35.00, '2027-01-01', '🌾', 'active', 5),
(10, 'ING010', 'น้ำปลา',         5, 14, 10.000,  45.00, '2027-06-01', '🧴', 'active', 5),
(11, 'ING011', 'น้ำตาล',         5, 1,   4.000,  28.00, '2028-01-01', '🍚', 'active', 5),
(12, 'ING012', 'น้ำส้มสายชู',   5, 14,  1.000,  25.00, '2026-12-01', '🍶', 'active', 5),
(13, 'ING013', 'น้ำดื่ม',        6, 17,  6.000,  90.00, '2027-03-01', '💧', 'active', 5),
(14, 'ING014', 'น้ำส้ม',         6, 14, 24.000,  20.00, '2026-05-01', '🍊', 'active', 5),
(15, 'ING015', 'ไข่ไก่',         1, 12, 60.000,   5.00, '2026-04-08', '🥚', 'active', 5),
(16, 'ING016', 'หอมใหญ่',        2, 5,  10.000,  18.00, '2026-04-12', '🧅', 'active', 5),
(17, 'ING017', 'กระเทียม',       2, 9,   8.000,  30.00, '2026-04-20', '🧄', 'active', 5),
(18, 'ING018', 'น้ำมันพืช',      5, 14,  3.000,  65.00, '2027-08-01', '🫙', 'active', 5),
(19, 'ING019', 'แป้งสาลี',       4, 1,  15.000,  40.00, '2026-10-01', '🌾', 'active', 5),
(20, 'ING020', 'นมสด',           6, 10,  5.000,  22.00, '2026-03-31', '🥛', 'active', 5);

-- ─── transactions (ประวัติ) ──────────────────────────────────
-- ปิด trigger ชั่วคราวเพราะ stock_qty ถูกตั้งค่าไว้แล้วใน INSERT ingredients
-- (ในการใช้งานจริง ควรเริ่มจาก stock=0 แล้วให้ transaction ปรับค่า)
SET @OLD_SQL_MODE = @@SQL_MODE;
SET SQL_MODE = 'NO_ENGINE_SUBSTITUTION';

INSERT INTO transactions (txn_id, txn_type, ingredient_id, qty, price_per_unit, reason, performed_by, txn_date) VALUES
(1,  'receive',  1,  10.000, 280.00, 'ซื้อจากตลาดสดไท',         1, '2026-03-20 09:00:00'),
(2,  'withdraw', 1,   2.000, 280.00, 'ใช้ปรุงอาหาร',             1, '2026-03-21 11:30:00'),
(3,  'receive',  4,  15.000,  25.00, 'ซื้อจากตลาด',              1, '2026-03-22 08:00:00'),
(4,  'withdraw', 2,   3.000, 180.00, 'ของเสีย',                  1, '2026-03-23 14:00:00'),
(5,  'receive',  9,  20.000,  35.00, 'ซื้อเพิ่มสต็อก',           1, '2026-03-24 10:00:00'),
(6,  'withdraw', 3,   1.000, 120.00, 'ใช้ปรุงอาหาร',             4, '2026-03-24 12:00:00'),
(7,  'receive',  15, 60.000,   5.00, 'ซื้อจากตลาด',              4, '2026-03-24 08:30:00'),
(8,  'withdraw', 5,   0.500,  40.00, 'ทำหก',                     1, '2026-03-25 09:00:00'),
(9,  'receive',  7,  10.000,  15.00, 'ซื้อจากตลาด',              4, '2026-03-25 07:00:00'),
(10, 'withdraw', 11,  1.000,  28.00, 'ใช้ปรุงอาหาร',             1, '2026-03-25 10:00:00'),
(11, 'receive',  18,  3.000,  65.00, 'ซื้อจากห้างฯ',             4, '2026-03-26 09:00:00'),
(12, 'withdraw', 2,   2.000, 180.00, 'ใช้ปรุงอาหาร',             4, '2026-03-26 11:00:00'),
(13, 'receive',  20,  5.000,  22.00, 'ซื้อจากร้านสะดวกซื้อ',    1, '2026-03-26 08:00:00'),
(14, 'withdraw', 15,  6.000,   5.00, 'พนักงานรับประทาน',         4, '2026-03-26 12:30:00'),
(15, 'withdraw', 4,   3.000,  25.00, 'ใช้ปรุงอาหาร',             1, '2026-03-27 09:30:00');

SET SQL_MODE = @OLD_SQL_MODE;

-- ─── orders ──────────────────────────────────────────────────
INSERT INTO orders (order_id, status, requested_by, approved_by, note, requested_at, approved_at) VALUES
(1, 'pending',  1, NULL, 'วัตถุดิบใกล้หมด ต้องการเร่งด่วน',  '2026-03-25 08:00:00', NULL),
(2, 'pending',  4, NULL, 'สั่งเพิ่มสต็อกประจำสัปดาห์',         '2026-03-25 09:30:00', NULL),
(3, 'approved', 1, 5,    'สั่งซื้อประจำเดือน',                  '2026-03-10 08:00:00', '2026-03-10 09:00:00'),
(4, 'rejected', 4, 5,    'ของหมด ต้องการด่วน',                  '2026-03-15 10:00:00', '2026-03-15 11:30:00');

-- ─── order_items ─────────────────────────────────────────────
INSERT INTO order_items (item_id, order_id, ingredient_id, qty, price_per_unit) VALUES
-- Order 1: รออนุมัติ
(1, 1, 5,  10.000,  40.00),
(2, 1, 12,  5.000,  25.00),
-- Order 2: รออนุมัติ
(3, 2, 1,  20.000, 280.00),
(4, 2, 3,   5.000, 120.00),
(5, 2, 15, 30.000,   5.00),
-- Order 3: อนุมัติแล้ว
(6, 3, 9,  30.000,  35.00),
(7, 3, 10,  6.000,  45.00),
-- Order 4: ปฏิเสธ
(8, 4, 2,  15.000, 180.00);

-- ─── audit_logs ──────────────────────────────────────────────
INSERT INTO audit_logs (log_id, table_name, record_id, action, old_values, new_values, changed_by) VALUES
(1, 'orders', 3, 'UPDATE',
   '{"status":"pending"}',
   '{"status":"approved","approved_by":5}',
   5),
(2, 'orders', 4, 'UPDATE',
   '{"status":"pending"}',
   '{"status":"rejected","approved_by":5}',
   5),
(3, 'ingredients', 5, 'UPDATE',
   '{"status":"active"}',
   '{"status":"active","stock_qty":2.500}',
   1);

-- ============================================================
-- USEFUL QUERIES — ตัวอย่างการใช้งาน
-- ============================================================

/*
-- 1. Dashboard: ภาพรวมสต็อก
SELECT
  COUNT(*)                                              AS total_active,
  SUM(stock_qty * price_per_unit)                       AS total_value,
  SUM(CASE WHEN stock_qty <= 5  THEN 1 ELSE 0 END)     AS low_stock_count,
  SUM(CASE WHEN DATEDIFF(expiry_date, CURDATE()) <= 7
           THEN 1 ELSE 0 END)                           AS expiring_soon_count
FROM ingredients WHERE status = 'active';

-- 2. วัตถุดิบใกล้หมด
SELECT * FROM v_dashboard WHERE stock_status IN ('critical','low') ORDER BY stock_qty ASC;

-- 3. วัตถุดิบใกล้หมดอายุ (7 วัน)
SELECT * FROM v_dashboard WHERE expiry_status IN ('critical','warning') ORDER BY days_to_expiry ASC;

-- 4. ประวัติรายการของพนักงาน
SELECT * FROM v_transactions WHERE performed_by_name = 'สมชาย ใจดี' ORDER BY txn_date DESC;

-- 5. รายงานสรุปต้นทุน เดือนมีนาคม 2569
CALL sp_report_summary('2026-03-01', '2026-03-31');

-- 6. อนุมัติ order #1 โดย admin (user_id=5)
CALL sp_approve_order(1, 5);

-- 7. ค้นหาวัตถุดิบ
SELECT * FROM v_ingredients WHERE name LIKE '%หมู%' AND status = 'active';

-- 8. มูลค่าสต็อกตามหมวดหมู่
SELECT category_name, category_emoji,
       COUNT(*)                    AS item_count,
       SUM(stock_qty)              AS total_qty,
       SUM(total_value)            AS total_value
FROM v_ingredients
WHERE status = 'active'
GROUP BY category_name, category_emoji
ORDER BY total_value DESC;

-- 9. รายการที่ทำในช่วงวันที่
SELECT * FROM v_transactions
WHERE txn_date BETWEEN '2026-03-01 00:00:00' AND '2026-03-31 23:59:59'
ORDER BY txn_date DESC;

-- 10. มูลค่าความเสียหาย (ของเสีย + ทิ้ง)
SELECT SUM(total_price) AS damage_cost
FROM transactions
WHERE reason IN ('ของเสีย','ทิ้ง');
*/

-- ============================================================
-- ตรวจสอบผลลัพธ์
-- ============================================================
SELECT 'users'        AS tbl, COUNT(*) AS rows FROM users
UNION ALL SELECT 'categories',   COUNT(*) FROM categories
UNION ALL SELECT 'units',        COUNT(*) FROM units
UNION ALL SELECT 'ingredients',  COUNT(*) FROM ingredients
UNION ALL SELECT 'transactions', COUNT(*) FROM transactions
UNION ALL SELECT 'orders',       COUNT(*) FROM orders
UNION ALL SELECT 'order_items',  COUNT(*) FROM order_items
UNION ALL SELECT 'audit_logs',   COUNT(*) FROM audit_logs;
