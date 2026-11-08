<?php
// ============================================================
//  index.php — ระบบจัดการวัตถุดิบครัว
//  วางไฟล์ที่: C:\xampp\htdocs\kitchen\index.php
//  เปิดที่:    http://localhost/kitchen/index.php
//  Tables:     users, categories, units, ingredients,
//              transactions, orders, order_items, audit_logs
// ============================================================
session_start();

define('DB_HOST','localhost');
define('DB_USER','root');
define('DB_PASS','');
define('DB_NAME','kitchen_inventory');

function db():PDO{
    static $p=null;
    if(!$p){
        try{
            $p=new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4",
                DB_USER,DB_PASS,[
                PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES=>false]);
        }catch(PDOException $e){
            if(isset($_GET['act'])){
                header('Content-Type:application/json');
                die(json_encode(['ok'=>false,'msg'=>'DB Error: '.$e->getMessage()]));
            }
            die('<div style="font-family:sans-serif;padding:40px;color:#b91c1c">
                <h2>❌ เชื่อมต่อฐานข้อมูลไม่ได้</h2>
                <p>'.$e->getMessage().'</p>
                <p style="color:#666">ตรวจสอบ: XAMPP MySQL กำลังรันอยู่ และ import SQL แล้ว</p>
                </div>');
        }
    }
    return $p;
}

function jres(bool $ok,$data=null,string $msg='',int $code=200):void{
    http_response_code($code);
    header('Content-Type:application/json;charset=utf-8');
    echo json_encode(['ok'=>$ok,'data'=>$data,'msg'=>$msg],JSON_UNESCAPED_UNICODE);
    exit;
}

function me():?array { return $_SESSION['user']??null; }
function role():string { return $_SESSION['user']['role']??''; }
function uid():int { return intval($_SESSION['user']['user_id']??0); }
function body():array { return (array)json_decode(file_get_contents('php://input'),true); }
function needLogin():void { if(!me()) jres(false,null,'กรุณาเข้าสู่ระบบ',401); }
function isAdmin():bool { return role()==='admin'; }

// ── helper: get ingredient with joins ────────────────────────
function getIng(PDO $db,int $id):?array{
    $s=$db->prepare('SELECT i.*,c.name cat_name,c.emoji cat_emoji,u.name unit_name,
                     ROUND(COALESCE((SELECT SUM(l.qty_remaining*l.price_per_unit) FROM ingredient_lots l WHERE l.ingredient_id=i.ingredient_id),0),2) total_value,
                     DATEDIFF(i.expiry_date,CURDATE()) days_exp
                     FROM ingredients i
                     JOIN categories c ON c.category_id=i.category_id
                     JOIN units u ON u.unit_id=i.unit_id
                     WHERE i.ingredient_id=?');
    $s->execute([$id]); return $s->fetch() ?: null;
}

// ── audit log ────────────────────────────────────────────────
function audit(string $tbl,int $rid,string $action,$old=null,$new=null):void{
    try{
        db()->prepare('INSERT INTO audit_logs(table_name,record_id,action,old_values,new_values,changed_by)
                       VALUES(?,?,?,?,?,?)')->execute([
            $tbl,$rid,$action,
            $old?json_encode($old,JSON_UNESCAPED_UNICODE):null,
            $new?json_encode($new,JSON_UNESCAPED_UNICODE):null,
            uid()?:null
        ]);
    }catch(Throwable){}
}

// ── stock helpers: อ่านสต็อก (ล็อกแถว) แล้วตั้งค่าตรงๆ กันบวก/หักซ้ำจาก trigger ──
function lockStock(PDO $db,int $ingId):float{
    $s=$db->prepare('SELECT stock_qty FROM ingredients WHERE ingredient_id=? FOR UPDATE');
    $s->execute([$ingId]); $v=$s->fetchColumn();
    if($v===false) throw new Exception('ไม่พบวัตถุดิบ');
    return floatval($v);
}
function setStock(PDO $db,int $ingId,float $qty):void{
    $db->prepare('UPDATE ingredients SET stock_qty=?,updated_at=NOW() WHERE ingredient_id=?')
       ->execute([max(0,$qty),$ingId]);
}

// ── ล็อตวัตถุดิบ (FEFO: หมดอายุก่อนตัดก่อน, ไม่มีวันหมดอายุตัดหลังสุด) ──
function ensureLots(PDO $db):void{
    if($db->query("SHOW TABLES LIKE 'ingredient_lots'")->fetchColumn()) return;
    try{
        $db->exec("CREATE TABLE ingredient_lots(
            lot_id INT AUTO_INCREMENT PRIMARY KEY,
            ingredient_id INT NOT NULL,
            qty_received DECIMAL(12,3) NOT NULL,
            qty_remaining DECIMAL(12,3) NOT NULL,
            price_per_unit DECIMAL(12,4) NOT NULL DEFAULT 0,
            expiry_date DATE NULL,
            received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            txn_id INT NULL,
            source VARCHAR(20) NOT NULL DEFAULT 'receive',
            KEY idx_ing(ingredient_id,qty_remaining,expiry_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // ยกยอดสต็อกเดิมเป็นล็อตเริ่มต้น
        $db->exec("INSERT INTO ingredient_lots(ingredient_id,qty_received,qty_remaining,price_per_unit,expiry_date,source)
                   SELECT ingredient_id,stock_qty,stock_qty,price_per_unit,expiry_date,'opening'
                   FROM ingredients WHERE stock_qty>0");
    }catch(Throwable $e){
        $db->exec('DROP TABLE IF EXISTS ingredient_lots'); throw $e;
    }
}
function addLot(PDO $db,int $ingId,float $qty,float $price,$expiry,?int $txnId,string $source):void{
    if($qty<=0) return;
    $db->prepare('INSERT INTO ingredient_lots(ingredient_id,qty_received,qty_remaining,price_per_unit,expiry_date,txn_id,source)
                  VALUES(?,?,?,?,?,?,?)')->execute([$ingId,$qty,$qty,$price,($expiry?:null),$txnId,$source]);
}
// ตัดสต็อกจากล็อต: หมดอายุก่อน -> ก่อน, ไม่มีวันหมดอายุ -> หลังสุด, วันที่เท่ากันเอารับเข้าก่อน
// คืนค่า "ต้นทุนรวม" ของจำนวนที่ตัด
function consumeLots(PDO $db,int $ingId,float $qty):float{
    $s=$db->prepare('SELECT lot_id,qty_remaining,price_per_unit FROM ingredient_lots
                     WHERE ingredient_id=? AND qty_remaining>0
                     ORDER BY (expiry_date IS NULL),expiry_date,received_at,lot_id FOR UPDATE');
    $s->execute([$ingId]);
    $need=$qty; $cost=0.0;
    $up=$db->prepare('UPDATE ingredient_lots SET qty_remaining=? WHERE lot_id=?');
    foreach($s->fetchAll() as $l){
        if($need<=0.0000001) break;
        $rem=floatval($l['qty_remaining']);
        $take=min($rem,$need);
        $up->execute([round($rem-$take,3),$l['lot_id']]);
        $cost+=$take*floatval($l['price_per_unit']);
        $need-=$take;
    }
    if($need>0.0000001){ // ล็อตไม่พอ (ข้อมูลเก่าไม่ตรง) -> คิดส่วนที่เหลือที่ราคาปัจจุบัน
        $ps=$db->prepare('SELECT price_per_unit FROM ingredients WHERE ingredient_id=?');
        $ps->execute([$ingId]); $cost+=$need*floatval($ps->fetchColumn());
    }
    return $cost;
}
// วันหมดอายุของวัตถุดิบ = ล็อตที่เหลือที่หมดอายุใกล้สุด
function refreshExpiry(PDO $db,int $ingId):void{
    $db->prepare('UPDATE ingredients SET expiry_date=(SELECT MIN(l.expiry_date) FROM ingredient_lots l
                  WHERE l.ingredient_id=? AND l.qty_remaining>0) WHERE ingredient_id=?')->execute([$ingId,$ingId]);
}

// ─────────────────────────────────────────────────────────────
// AJAX HANDLER
// ─────────────────────────────────────────────────────────────
if(isset($_GET['act'])){
    $act = $_GET['act'];
    $b   = body();
    $db  = db();
    ensureLots($db);
    $id  = intval($_GET['id']??0);

    switch($act){

    // ── AUTH ──────────────────────────────────────────────────
    case 'login':
        $email=trim($b['email']??''); $pass=trim($b['pass']??''); $rl=trim($b['role']??'staff');
        if(!$email||!$pass) jres(false,null,'กรุณากรอกอีเมลและรหัสผ่าน',400);
        $s=$db->prepare('SELECT * FROM users WHERE email=? AND role=? LIMIT 1');
        $s->execute([$email,$rl]); $u=$s->fetch();
        if(!$u||$pass!==$u['password_hash']) jres(false,null,'อีเมลหรือรหัสผ่านไม่ถูกต้อง',401);
        if($u['status']==='pending')   jres(false,null,'บัญชีรออนุมัติจากผู้ดูแลระบบ',403);
        if($u['status']==='suspended') jres(false,null,'บัญชีถูกระงับสิทธิ์',403);
        unset($u['password_hash']); $_SESSION['user']=$u;
        jres(true,$u,'เข้าสู่ระบบสำเร็จ');

    case 'logout':
        session_destroy(); jres(true,null,'ออกจากระบบแล้ว');

    // ── INGREDIENTS ───────────────────────────────────────────
    case 'ings':
        needLogin();
        $sql='SELECT i.*,c.name cat_name,c.emoji cat_emoji,u.name unit_name,
              ROUND(COALESCE((SELECT SUM(l.qty_remaining*l.price_per_unit) FROM ingredient_lots l WHERE l.ingredient_id=i.ingredient_id),0),2) total_value,
              DATEDIFF(i.expiry_date,CURDATE()) days_exp,
              (SELECT t.price_per_unit FROM transactions t
                WHERE t.ingredient_id=i.ingredient_id AND t.txn_type="receive"
                ORDER BY t.txn_date DESC,t.txn_id DESC LIMIT 1) last_receive_price
              FROM ingredients i
              JOIN categories c ON c.category_id=i.category_id
              JOIN units u ON u.unit_id=i.unit_id WHERE 1=1'; $p=[];
        if(isAdmin()&&!empty($_GET['status'])){$sql.=' AND i.status=?';$p[]=$_GET['status'];}
        else $sql.=' AND i.status="active"';
        if(!empty($_GET['cat'])){$sql.=' AND i.category_id=?';$p[]=intval($_GET['cat']);}
        if(!empty($_GET['q'])){$q='%'.$_GET['q'].'%';
            $sql.=' AND(i.code LIKE? OR i.name LIKE? OR c.name LIKE?)';$p=array_merge($p,[$q,$q,$q]);}
        $sql.=' ORDER BY i.code';
        $s=$db->prepare($sql);$s->execute($p);
        jres(true,$s->fetchAll());

    case 'ing':
        needLogin();
        $r=getIng($db,$id);
        if(!$r) jres(false,null,'ไม่พบวัตถุดิบ',404);
        jres(true,$r);

    case 'save_ing':
        needLogin(); if(!isAdmin()) jres(false,null,'ไม่มีสิทธิ์',403);
        if(empty($b['code'])||empty($b['name'])||empty($b['category_id'])||empty($b['unit_id']))
            jres(false,null,'กรุณากรอกข้อมูลให้ครบ',400);
        try{
        if($id){
            $old=getIng($db,$id);
            if(!$old) jres(false,null,'ไม่พบวัตถุดิบ',404);
            $sets=[]; $p=[];
            foreach(['code','name','category_id','unit_id','price_per_unit','image_url','status'] as $k){
                if(!array_key_exists($k,$b)) continue;
                $v=$b[$k];
                if($v===''&&$k==='image_url') $v=null;   // ล้างค่าได้
                $sets[]="$k=?"; $p[]=$v;
            }
            $newStock=null;
            if(isset($b['stock_qty'])&&$b['stock_qty']!==''){
                $ns=floatval($b['stock_qty']);
                if($ns<0) jres(false,null,'สต็อกต้องไม่ติดลบ',400);
                if(abs($ns-floatval($old['stock_qty']))>0.0001) $newStock=$ns;
            }
            if(!$sets&&$newStock===null) jres(false,null,'ไม่มีข้อมูลอัปเดต',400);
            $db->beginTransaction();
            try{
                if($sets){
                    $p[]=$id;
                    $db->prepare('UPDATE ingredients SET '.implode(',',$sets).',updated_at=NOW() WHERE ingredient_id=?')->execute($p);
                }
                if($newStock!==null){
                    $before=lockStock($db,$id);
                    $delta=$newStock-$before;
                    if($delta>0){   // เพิ่มยอด -> สร้างล็อตปรับยอด
                        $lp=(isset($b['price_per_unit'])&&$b['price_per_unit']!=='')?floatval($b['price_per_unit']):floatval($old['price_per_unit']);
                        addLot($db,$id,$delta,$lp,$b['expiry_date']??null,null,'adjust');
                    } else {        // ลดยอด -> ตัดล็อตที่หมดอายุก่อน
                        consumeLots($db,$id,-$delta);
                    }
                    setStock($db,$id,$newStock);
                }
                refreshExpiry($db,$id);
                $db->commit();
            }catch(Throwable $e){ $db->rollBack(); throw $e; }
            $new=getIng($db,$id);
            $new['edit_note']=trim($b['edit_note']??'');
            audit('ingredients',$id,'UPDATE',$old,$new);
            jres(true,null,'แก้ไขสำเร็จ');
        } else {
            $s=$db->prepare('INSERT INTO ingredients(code,name,category_id,unit_id,stock_qty,price_per_unit,expiry_date,image_url,status,created_by)
                             VALUES(?,?,?,?,?,?,?,?,"active",?)');
            $s->execute([$b['code'],$b['name'],intval($b['category_id']),intval($b['unit_id']),
                floatval($b['stock_qty']??0),floatval($b['price_per_unit']??0),
                ($b['expiry_date']??'')?:null,($b['image_url']??'')?:null,uid()]);
            $newId=$db->lastInsertId();
            $st0=floatval($b['stock_qty']??0);
            if($st0>0){
                addLot($db,intval($newId),$st0,floatval($b['price_per_unit']??0),$b['expiry_date']??null,null,'opening');
                refreshExpiry($db,intval($newId));
            }
            audit('ingredients',$newId,'INSERT',null,getIng($db,$newId));
            jres(true,['id'=>$newId],'เพิ่มสำเร็จ',201);
        }
        }catch(PDOException $e){
            jres(false,null,str_contains($e->getMessage(),'Duplicate')?'รหัสวัตถุดิบซ้ำ':$e->getMessage(),409);
        }catch(Throwable $e){
            jres(false,null,$e->getMessage(),500);
        }

    case 'lots':
        needLogin();
        $s=$db->prepare('SELECT lot_id,qty_received,qty_remaining,price_per_unit,expiry_date,received_at,source,
                         DATEDIFF(expiry_date,CURDATE()) days_exp
                         FROM ingredient_lots WHERE ingredient_id=? AND qty_remaining>0
                         ORDER BY (expiry_date IS NULL),expiry_date,received_at,lot_id');
        $s->execute([$id]); jres(true,$s->fetchAll());

    case 'ing_log':
        needLogin(); if(!isAdmin()) jres(false,null,'ไม่มีสิทธิ์',403);
        $s=$db->prepare('SELECT a.*,u.full_name by_name FROM audit_logs a
                         LEFT JOIN users u ON u.user_id=a.changed_by
                         WHERE a.table_name="ingredients" AND a.record_id=?');
        $s->execute([$id]);
        jres(true,array_slice(array_reverse($s->fetchAll()),0,15));

    case 'del_ing':
        needLogin(); if(!isAdmin()) jres(false,null,'ไม่มีสิทธิ์',403);
        $old=getIng($db,$id);
        if(!$old) jres(false,null,'ไม่พบวัตถุดิบ',404);
        $h=$db->prepare('SELECT (SELECT COUNT(*) FROM transactions WHERE ingredient_id=?)
                              +(SELECT COUNT(*) FROM order_items WHERE ingredient_id=?)');
        $h->execute([$id,$id]); $used=intval($h->fetchColumn())>0;
        $db->beginTransaction();
        try{
            if($used){   // มีประวัติอ้างอิงอยู่ -> ลบแถวจริงไม่ได้ ซ่อนออกจากตารางแทน (ประวัติยังอยู่ครบ)
                $db->prepare('UPDATE ingredients SET status="inactive",updated_at=NOW() WHERE ingredient_id=?')->execute([$id]);
            } else {
                $db->prepare('DELETE FROM ingredient_lots WHERE ingredient_id=?')->execute([$id]);
                $db->prepare('DELETE FROM ingredients WHERE ingredient_id=?')->execute([$id]);
            }
            $db->commit();
        }catch(Throwable $e){ $db->rollBack(); jres(false,null,$e->getMessage(),500); }
        audit('ingredients',$id,'DELETE',$old,null);
        jres(true,['mode'=>$used?'hidden':'deleted'],'ลบวัตถุดิบแล้ว');

    // ── TRANSACTIONS ──────────────────────────────────────────
    case 'receive':
        needLogin();
        $ingId=intval($b['ing_id']??0); $qty=floatval($b['qty']??0);
        if(!$ingId||$qty<=0) jres(false,null,'กรุณากรอกข้อมูลให้ครบ',400);
        $price=floatval($b['price']??0);
        $givenPrice=$price>0;
        if(!$givenPrice){
            $ps=$db->prepare('SELECT price_per_unit FROM ingredients WHERE ingredient_id=?');
            $ps->execute([$ingId]); $price=floatval($ps->fetchColumn());
        }
        $db->beginTransaction();
        try{
            $before=lockStock($db,$ingId);
            $db->prepare('INSERT INTO transactions(txn_type,ingredient_id,qty,price_per_unit,reason,expiry_date,note,performed_by)
                          VALUES("receive",?,?,?,?,?,?,?)')
               ->execute([$ingId,$qty,$price,$b['reason']??null,$b['expiry_date']??null,$b['note']??null,uid()]);
            $txnId=$db->lastInsertId();
            setStock($db,$ingId,$before+$qty);          // ตั้งค่าตรงๆ กันบวกซ้ำจาก trigger
            if($givenPrice)   // ราคาที่กรอกตอนรับเข้า = ราคา/หน่วยปัจจุบันของวัตถุดิบ
                $db->prepare('UPDATE ingredients SET price_per_unit=? WHERE ingredient_id=?')->execute([$price,$ingId]);
            addLot($db,$ingId,$qty,$price,$b['expiry_date']??null,intval($txnId),'receive');   // 1 รอบรับเข้า = 1 ล็อต
            refreshExpiry($db,$ingId);
            $db->commit();
            jres(true,['txn_id'=>$txnId,'stock'=>$before+$qty],'บันทึกการรับเข้าสำเร็จ',201);
        }catch(Throwable $e){$db->rollBack();jres(false,null,$e->getMessage(),500);}

    case 'withdraw':
        needLogin();
        $ingId=intval($b['ing_id']??0); $qty=floatval($b['qty']??0);
        $reason=$b['reason']??''; $note=$b['note']??null;
        if(!$ingId||$qty<=0||!$reason) jres(false,null,'กรุณากรอกข้อมูลให้ครบ',400);
        $db->beginTransaction();
        try{
            $before=lockStock($db,$ingId);
            if($qty>$before){$db->rollBack();jres(false,null,'สต็อกไม่พอ (เหลือ '.round($before,3).' หน่วย)',409);}
            $cost=consumeLots($db,$ingId,$qty);          // ตัดล็อตที่หมดอายุก่อน
            $price=round($cost/$qty,4);                   // ราคาเฉลี่ยถ่วงน้ำหนักตามล็อตที่ถูกตัด
            $db->prepare('INSERT INTO transactions(txn_type,ingredient_id,qty,price_per_unit,reason,note,performed_by)
                          VALUES("withdraw",?,?,?,?,?,?)')->execute([$ingId,$qty,$price,$reason,$note,uid()]);
            $txnId=$db->lastInsertId();
            setStock($db,$ingId,$before-$qty);
            refreshExpiry($db,$ingId);
            $db->commit();
            jres(true,['txn_id'=>$txnId],'บันทึกการเบิกสำเร็จ',201);
        }catch(Throwable $e){$db->rollBack();jres(false,null,$e->getMessage(),500);}

    case 'txns':
        needLogin();
        $sql='SELECT t.txn_id,t.txn_type,t.qty,t.price_per_unit,
              ROUND(t.qty*t.price_per_unit,2) total_price,
              t.reason,t.note,t.expiry_date,t.txn_date,
              i.name ing_name,i.image_url ing_img,u.name unit_name,
              us.full_name performed_name
              FROM transactions t
              JOIN ingredients i ON i.ingredient_id=t.ingredient_id
              JOIN units u ON u.unit_id=i.unit_id
              JOIN users us ON us.user_id=t.performed_by
              WHERE 1=1'; $p=[];
        if(!isAdmin()){$sql.=' AND t.performed_by=?';$p[]=uid();}
        if(!empty($_GET['type'])){$sql.=' AND t.txn_type=?';$p[]=$_GET['type'];}
        if(!empty($_GET['from'])){$sql.=' AND DATE(t.txn_date)>=?';$p[]=$_GET['from'];}
        if(!empty($_GET['to'])){$sql.=' AND DATE(t.txn_date)<=?';$p[]=$_GET['to'];}
        $sql.=' ORDER BY t.txn_date DESC';
        $s=$db->prepare($sql);$s->execute($p);$rows=$s->fetchAll();
        $buy=$dmg=0;
        foreach($rows as $r2){
            if($r2['txn_type']==='receive') $buy+=$r2['total_price'];
            if($r2['txn_type']==='withdraw'&&in_array($r2['reason'],['ของเสีย','ทิ้ง'])) $dmg+=$r2['total_price'];
        }
        jres(true,['items'=>$rows,'buy'=>round($buy,2),'dmg'=>round($dmg,2),'count'=>count($rows)]);

    // ── DASHBOARD ─────────────────────────────────────────────
    case 'dash':
        needLogin(); if(!isAdmin()) jres(false,null,'ไม่มีสิทธิ์',403);
        $stats=$db->query('SELECT COUNT(*) total,(SELECT ROUND(COALESCE(SUM(l.qty_remaining*l.price_per_unit),0),2) FROM ingredient_lots l JOIN ingredients x ON x.ingredient_id=l.ingredient_id WHERE x.status="active") val,
            SUM(CASE WHEN stock_qty<=5 THEN 1 ELSE 0 END) low_stock,
            SUM(CASE WHEN expiry_date IS NOT NULL AND DATEDIFF(expiry_date,CURDATE()) BETWEEN 0 AND 7 THEN 1 ELSE 0 END) exp_soon
            FROM ingredients WHERE status="active"')->fetch();
        $low=$db->query('SELECT i.*,c.name cat_name,c.emoji cat_emoji,u.name unit_name
            FROM ingredients i JOIN categories c ON c.category_id=i.category_id JOIN units u ON u.unit_id=i.unit_id
            WHERE i.status="active" AND i.stock_qty<=5 ORDER BY i.stock_qty ASC LIMIT 10')->fetchAll();
        $exp=$db->query('SELECT i.*,c.name cat_name,u.name unit_name,DATEDIFF(i.expiry_date,CURDATE()) days
            FROM ingredients i JOIN categories c ON c.category_id=i.category_id JOIN units u ON u.unit_id=i.unit_id
            WHERE i.status="active" AND i.expiry_date IS NOT NULL AND DATEDIFF(i.expiry_date,CURDATE()) BETWEEN 0 AND 7
            ORDER BY i.expiry_date ASC LIMIT 10')->fetchAll();
        jres(true,['stats'=>$stats,'low'=>$low,'exp'=>$exp]);

    // ── CATEGORIES ────────────────────────────────────────────
    case 'cats':
        needLogin();
        jres(true,$db->query('SELECT * FROM categories WHERE is_active=1 ORDER BY category_id')->fetchAll());

    case 'save_cat':
        needLogin(); if(!isAdmin()) jres(false,null,'ไม่มีสิทธิ์',403);
        if(empty($b['name'])) jres(false,null,'กรุณากรอกชื่อหมวดหมู่',400);
        if($id){
            $db->prepare('UPDATE categories SET name=?,emoji=? WHERE category_id=?')
               ->execute([$b['name'],$b['emoji']??'📦',$id]);
        } else {
            $db->prepare('INSERT INTO categories(name,emoji)VALUES(?,?)')->execute([$b['name'],$b['emoji']??'📦']);
        }
        jres(true,null,'บันทึกสำเร็จ');

    case 'del_cat':
        needLogin(); if(!isAdmin()) jres(false,null,'ไม่มีสิทธิ์',403);
        $c=$db->prepare('SELECT COUNT(*) FROM ingredients WHERE category_id=? AND status="active"');
        $c->execute([$id]);
        if($c->fetchColumn()>0) jres(false,null,'ไม่สามารถลบได้ มีวัตถุดิบใช้หมวดหมู่นี้',409);
        $db->prepare('UPDATE categories SET is_active=0 WHERE category_id=?')->execute([$id]);
        jres(true,null,'ลบสำเร็จ');

    // ── UNITS ─────────────────────────────────────────────────
    case 'units':
        needLogin();
        jres(true,$db->query('SELECT * FROM units WHERE is_active=1 ORDER BY unit_id')->fetchAll());

    case 'save_unit':
        needLogin(); if(!isAdmin()) jres(false,null,'ไม่มีสิทธิ์',403);
        if(empty($b['name'])) jres(false,null,'กรุณากรอกหน่วยนับ',400);
        try{
            if($id) $db->prepare('UPDATE units SET name=? WHERE unit_id=?')->execute([$b['name'],$id]);
            else    $db->prepare('INSERT INTO units(name)VALUES(?)')->execute([$b['name']]);
            jres(true,null,'บันทึกสำเร็จ');
        }catch(PDOException){ jres(false,null,'ชื่อหน่วยนับซ้ำ',409); }

    case 'del_unit':
        needLogin(); if(!isAdmin()) jres(false,null,'ไม่มีสิทธิ์',403);
        $c=$db->prepare('SELECT COUNT(*) FROM ingredients WHERE unit_id=? AND status="active"');
        $c->execute([$id]);
        if($c->fetchColumn()>0) jres(false,null,'ไม่สามารถลบได้ มีวัตถุดิบใช้หน่วยนี้',409);
        $db->prepare('UPDATE units SET is_active=0 WHERE unit_id=?')->execute([$id]);
        jres(true,null,'ลบสำเร็จ');

    // ── USERS ─────────────────────────────────────────────────
    case 'users':
        needLogin(); if(!isAdmin()) jres(false,null,'ไม่มีสิทธิ์',403);
        $s=$db->prepare('SELECT user_id,full_name,email,phone,role,status,created_at FROM users WHERE role="staff" ORDER BY created_at DESC');
        $s->execute(); jres(true,$s->fetchAll());

    case 'save_user':
        needLogin(); if(!isAdmin()) jres(false,null,'ไม่มีสิทธิ์',403);
        if(!in_array($b['status']??'',['active','pending','suspended'])) jres(false,null,'สถานะไม่ถูกต้อง',400);
        $db->prepare('UPDATE users SET status=?,updated_at=NOW() WHERE user_id=? AND role="staff"')
           ->execute([$b['status'],$id]);
        jres(true,null,'อัปเดตสำเร็จ');

    case 'del_user':
        needLogin(); if(!isAdmin()) jres(false,null,'ไม่มีสิทธิ์',403);
        if($id===uid()) jres(false,null,'ไม่สามารถลบบัญชีตัวเองได้',400);
        $db->prepare('DELETE FROM users WHERE user_id=? AND role="staff"')->execute([$id]);
        jres(true,null,'ลบผู้ใช้แล้ว');

    case 'profile':
        needLogin();
        $sets=[]; $p=[];
        if(!empty($b['name'])){$sets[]='full_name=?';$p[]=$b['name'];}
        if(!empty($b['email'])){$sets[]='email=?';$p[]=$b['email'];}
        if(!empty($b['phone'])){$sets[]='phone=?';$p[]=$b['phone'];}
        if(!empty($b['pass'])){$sets[]='password_hash=?';$p[]=$b['pass'];}
        if(!$sets) jres(false,null,'ไม่มีข้อมูลที่จะอัปเดต',400);
        $sets[]='updated_at=NOW()'; $p[]=uid();
        $db->prepare('UPDATE users SET '.implode(',',$sets).' WHERE user_id=?')->execute($p);
        $s=$db->prepare('SELECT user_id,full_name,email,phone,role,status FROM users WHERE user_id=?');
        $s->execute([uid()]); $u=$s->fetch(); $_SESSION['user']=$u;
        jres(true,$u,'บันทึกสำเร็จ');

    // ── ORDERS ────────────────────────────────────────────────
    case 'orders':
        needLogin();
        $sql='SELECT o.*,u.full_name req_name,a.full_name appr_name
              FROM orders o JOIN users u ON u.user_id=o.requested_by
              LEFT JOIN users a ON a.user_id=o.approved_by WHERE 1=1'; $p=[];
        if(!isAdmin()){$sql.=' AND o.requested_by=?';$p[]=uid();}
        if(!empty($_GET['status'])){$sql.=' AND o.status=?';$p[]=$_GET['status'];}
        $sql.=' ORDER BY o.requested_at DESC';
        $s=$db->prepare($sql);$s->execute($p);$orders=$s->fetchAll();
        foreach($orders as &$ord){
            $s2=$db->prepare('SELECT oi.*,i.name ing_name,i.image_url ing_img,i.stock_qty,u2.name unit_name
                FROM order_items oi JOIN ingredients i ON i.ingredient_id=oi.ingredient_id
                JOIN units u2 ON u2.unit_id=i.unit_id WHERE oi.order_id=?');
            $s2->execute([$ord['order_id']]); $ord['items']=$s2->fetchAll();
        }
        jres(true,$orders);

    case 'create_order':
        needLogin();
        $items=$b['items']??[]; $note=$b['note']??'';
        $valid=array_filter($items,fn($x)=>!empty($x['ing_id'])&&floatval($x['qty']??0)>0);
        if(empty($valid)) jres(false,null,'กรุณาระบุรายการอย่างน้อย 1 รายการ',400);
        $db->beginTransaction();
        try{
            $s=$db->prepare('INSERT INTO orders(status,requested_by,note)VALUES("pending",?,?)');
            $s->execute([uid(),$note]); $oid=$db->lastInsertId();
            $s2=$db->prepare('INSERT INTO order_items(order_id,ingredient_id,qty,price_per_unit)VALUES(?,?,?,?)');
            foreach($valid as $it){
                $qty=floatval($it['qty']);
                $total=floatval($it['total']??0);                 // ราคารวมของรายการ (ที่พนักงานกรอก)
                if($total>0){ $price=round($total/$qty,4); }       // แปลงเป็นราคา/หน่วย
                else{$ps=$db->prepare('SELECT price_per_unit FROM ingredients WHERE ingredient_id=?');$ps->execute([intval($it['ing_id'])]);$price=floatval($ps->fetchColumn());}
                $s2->execute([$oid,intval($it['ing_id']),$qty,$price]);
            }
            $db->commit(); jres(true,['order_id'=>$oid],'ส่งคำสั่งซื้อสำเร็จ',201);
        }catch(Throwable $e){$db->rollBack();jres(false,null,$e->getMessage(),500);}

    case 'approve_order':
        needLogin(); if(!isAdmin()) jres(false,null,'ไม่มีสิทธิ์',403);
        $s=$db->prepare('SELECT * FROM orders WHERE order_id=? AND status="pending"');
        $s->execute([$id]); if(!$s->fetch()) jres(false,null,'ไม่พบ order หรือดำเนินการแล้ว',409);
        $db->beginTransaction();
        try{
            $db->prepare('UPDATE orders SET status="approved",approved_by=?,approved_at=NOW() WHERE order_id=?')
               ->execute([uid(),$id]);
            $db->commit(); jres(true,null,'อนุมัติสำเร็จ');
        }catch(Throwable $e){$db->rollBack();jres(false,null,$e->getMessage(),500);}

    case 'reject_order':
        needLogin(); if(!isAdmin()) jres(false,null,'ไม่มีสิทธิ์',403);
        $s=$db->prepare('UPDATE orders SET status="rejected",approved_by=?,approved_at=NOW() WHERE order_id=? AND status="pending"');
        $s->execute([uid(),$id]);
        if(!$s->rowCount()) jres(false,null,'ไม่พบ order หรือดำเนินการแล้ว',409);
        jres(true,null,'ปฏิเสธแล้ว');

    default: jres(false,null,'Unknown action: '.$act,400);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>ระบบจัดการวัตถุดิบครัว</title>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700&family=Prompt:wght@500;600;700&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Sarabun',sans-serif;font-size:15px;background:#f5f3ee;color:#1a1a1a}
:root{--ac:#f97316;--ac2:#ea580c;--g:#16a34a;--r:#dc2626;--y:#d97706;--bl:#2563eb;
      --mu:#6b7280;--bd:#e5e0d8;--wh:#fff;--bg2:#f5f3ee;--card:#fff}
/* LAYOUT */
#app{display:flex;min-height:100vh}
.sb{width:230px;background:#fff;border-right:1px solid var(--bd);display:flex;flex-direction:column;
    position:sticky;top:0;height:100vh;overflow-y:auto;flex-shrink:0}
.main{flex:1;padding:26px 30px;overflow-y:auto}
.sec{display:none}.sec.on{display:block}
/* SIDEBAR */
.sbl{padding:16px;border-bottom:1px solid var(--bd)}
.sbl-t{font-family:'Prompt',sans-serif;font-size:14px;font-weight:700}
.sbl-s{font-size:11px;color:var(--mu);margin-top:2px}
.nl{padding:8px 16px 2px;font-size:10px;font-weight:700;color:var(--mu);text-transform:uppercase;letter-spacing:.07em}
.ni{display:flex;align-items:center;gap:8px;padding:9px 16px;font-size:13px;cursor:pointer;
    color:var(--mu);border-left:3px solid transparent;transition:.1s;user-select:none}
.ni:hover{color:#1a1a1a;background:#faf8f5}
.ni.on{color:var(--ac);background:#fff7ed;border-left-color:var(--ac);font-weight:600}
.sbf{margin-top:auto;padding:13px 16px;border-top:1px solid var(--bd)}
.av{width:34px;height:34px;border-radius:50%;background:linear-gradient(135deg,var(--ac),var(--ac2));
    display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;color:#fff;flex-shrink:0}
/* BUTTONS */
.btn{display:inline-flex;align-items:center;justify-content:center;gap:5px;padding:8px 16px;
     border-radius:8px;border:1px solid var(--bd);background:#fff;color:#1a1a1a;
     font-family:'Sarabun',sans-serif;font-size:13px;font-weight:600;cursor:pointer;
     transition:.12s;white-space:nowrap}
.btn:hover{background:#faf8f5}.btn:disabled{opacity:.5;cursor:default}
.btn-p{background:var(--ac);color:#fff;border-color:var(--ac)}.btn-p:hover{background:var(--ac2)}
.btn-g{background:#15803d;color:#fff;border-color:#15803d}
.btn-r{background:var(--r);color:#fff;border-color:var(--r)}
.btn-y{background:var(--y);color:#fff;border-color:var(--y)}
.btn-sm{padding:5px 11px;font-size:12px;border-radius:6px}
.btn-fw{width:100%}
/* FORMS */
.fg{margin-bottom:13px}
.fg label{display:block;font-size:12px;color:var(--mu);margin-bottom:5px;font-weight:500}
.fg input,.fg select,.fg textarea{width:100%;padding:9px 12px;border:1px solid var(--bd);
    border-radius:8px;font-family:'Sarabun',sans-serif;font-size:14px;color:#1a1a1a;
    background:#fff;outline:none;transition:.12s}
.fg input:focus,.fg select:focus,.fg textarea:focus{border-color:var(--ac);box-shadow:0 0 0 3px rgba(249,115,22,.1)}
.fg textarea{resize:vertical;min-height:68px}
.g2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.g3{display:grid;grid-template-columns:repeat(3,1fr);gap:14px}
.g4{display:grid;grid-template-columns:repeat(4,1fr);gap:14px}
/* CARDS */
.card{background:var(--card);border:1px solid var(--bd);border-radius:12px;padding:18px 20px;margin-bottom:14px}
.ct{font-family:'Prompt',sans-serif;font-size:14px;font-weight:600;margin-bottom:12px;
    padding-bottom:10px;border-bottom:1px solid var(--bd)}
.sc{background:var(--bg2);border-radius:10px;padding:14px}
.sv{font-family:'Prompt',sans-serif;font-size:26px;font-weight:700;line-height:1.1}
.sl{font-size:11px;color:var(--mu);margin-top:4px}
/* BADGES */
.bdg{display:inline-flex;align-items:center;padding:3px 9px;border-radius:5px;font-size:11px;font-weight:600}
.bg{background:#dcfce7;color:#15803d}.by{background:#fef9c3;color:#854d0e}
.br{background:#fee2e2;color:#b91c1c}.bb{background:#dbeafe;color:#1d4ed8}
.bmu{background:#f3f4f6;color:#6b7280}
/* TABLE */
.tw{border:1px solid var(--bd);border-radius:12px;overflow:auto}
table{width:100%;border-collapse:collapse}
th{background:#f9f7f4;padding:9px 13px;text-align:left;font-size:11px;font-weight:700;color:var(--mu);white-space:nowrap}
td{padding:10px 13px;border-top:1px solid var(--bd);font-size:13px;vertical-align:middle}
tr:hover td{background:#fffbf5}
/* PAGE HEADER */
.ph{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:10px}
.ph-t{font-family:'Prompt',sans-serif;font-size:22px;font-weight:700}
.ph-s{font-size:13px;color:var(--mu);margin-top:3px}
/* INGREDIENT GRID */
.ig{display:grid;grid-template-columns:repeat(auto-fill,minmax(175px,1fr));gap:13px}
.ic{background:#fff;border:1px solid var(--bd);border-radius:12px;overflow:hidden;cursor:pointer;transition:.15s}
.ic:hover{border-color:var(--ac);transform:translateY(-2px);box-shadow:0 4px 14px rgba(0,0,0,.08)}
.ii{height:105px;background:#f5f3ee;display:flex;align-items:center;justify-content:center;font-size:46px}
.ib{padding:11px 12px}
.in{font-weight:600;font-size:14px;margin-bottom:2px}
.ic2{font-size:11px;color:var(--mu);margin-bottom:7px}
.if{display:flex;align-items:center;justify-content:space-between}
/* CAT PILLS */
.cp{display:flex;gap:7px;flex-wrap:wrap;margin-bottom:14px}
.cpl{padding:5px 13px;border-radius:999px;border:1px solid var(--bd);background:transparent;
     color:var(--mu);cursor:pointer;font-family:'Sarabun',sans-serif;font-size:13px;transition:.12s}
.cpl:hover{border-color:var(--ac);color:var(--ac)}
.cpl.on{background:var(--ac);color:#fff;border-color:var(--ac)}
/* SEARCH */
.sw{position:relative}
.sw .si{position:absolute;left:11px;top:50%;transform:translateY(-50%);color:var(--mu);font-size:14px;pointer-events:none}
.sw input{padding-left:34px}
/* MODAL */
.mo{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:500;
    display:flex;align-items:center;justify-content:center;padding:16px}
.mb{background:#fff;border-radius:16px;width:520px;max-width:96vw;max-height:90vh;overflow-y:auto;padding:26px}
.mb-sm{width:390px}
.mh{display:flex;align-items:center;justify-content:space-between;margin-bottom:18px}
.mt{font-family:'Prompt',sans-serif;font-size:17px;font-weight:700}
.mc{background:none;border:none;font-size:20px;cursor:pointer;color:var(--mu);line-height:1}
.mf{display:flex;gap:8px;justify-content:flex-end;margin-top:18px;padding-top:14px;border-top:1px solid var(--bd)}
/* DETAIL ROW */
.dr{display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid var(--bd);font-size:13px}
.dr:last-child{border-bottom:none}
.dl{color:var(--mu)}.dv{font-weight:600;text-align:right}
/* HISTORY */
.hi{display:flex;align-items:center;gap:10px;padding:11px 0;border-bottom:1px solid var(--bd)}
.hi:last-child{border-bottom:none}
.hic{width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0}
/* TOAST */
#toast{position:fixed;bottom:20px;right:20px;padding:11px 18px;border-radius:10px;font-size:13px;
       font-weight:600;z-index:9999;opacity:0;transition:.2s;pointer-events:none;max-width:360px}
#toast.on{opacity:1}
#toast.ok{background:#dcfce7;color:#15803d;border:1px solid #86efac}
#toast.er{background:#fee2e2;color:#b91c1c;border:1px solid #fca5a5}
#toast.wa{background:#fef9c3;color:#854d0e;border:1px solid #fde047}
/* LOGIN */
.lw{display:flex;align-items:center;justify-content:center;min-height:100vh;background:#f5f3ee}
.lb{background:#fff;border:1px solid var(--bd);border-radius:16px;padding:32px;width:390px;
    box-shadow:0 8px 24px rgba(0,0,0,.08)}
.rt{display:flex;gap:7px;margin-bottom:18px}
.rtb{flex:1;padding:8px;border-radius:8px;border:1px solid var(--bd);background:transparent;
     color:var(--mu);cursor:pointer;font-family:'Sarabun',sans-serif;font-size:13px;font-weight:500;transition:.12s}
.rtb.on{background:var(--ac);color:#fff;border-color:var(--ac)}
/* MISC */
.spin{display:inline-block;width:15px;height:15px;border:2px solid var(--bd);
      border-top-color:var(--ac);border-radius:50%;animation:sp .6s linear infinite;vertical-align:middle}
@keyframes sp{to{transform:rotate(360deg)}}
.load{display:flex;align-items:center;justify-content:center;padding:40px;color:var(--mu);gap:8px}
.empty{text-align:center;padding:50px 20px;color:var(--mu)}
.ei{font-size:40px;margin-bottom:10px}
.ur{display:flex;align-items:center;gap:11px;padding:12px;border-bottom:1px solid var(--bd)}
.ur:last-child{border-bottom:none}
.oir{display:flex;align-items:center;justify-content:space-between;padding:7px 10px;
     background:#f9f7f4;border-radius:8px;margin-bottom:5px}
.bar-wrap{margin-bottom:10px}
.bar-lbl{display:flex;justify-content:space-between;font-size:12px;color:var(--mu);margin-bottom:3px}
.bar{height:7px;background:#f0ede8;border-radius:3px;overflow:hidden}
.bar-fill{height:100%;border-radius:3px;background:var(--ac)}
@media(max-width:700px){.sb{display:none}.main{padding:14px}.g2,.g3,.g4{grid-template-columns:1fr}}
</style>
</head>
<body>

<!-- LOGIN -->
<div id="lp">
<div class="lw"><div class="lb">
  <div style="text-align:center;margin-bottom:22px">
    <div style="font-size:36px;margin-bottom:8px">🍳</div>
    <div style="font-family:'Prompt',sans-serif;font-size:18px;font-weight:700">ระบบจัดการวัตถุดิบ</div>
    <div style="font-size:12px;color:var(--mu);margin-top:2px">Kitchen Inventory Management</div>
  </div>
  <div class="rt">
    <button class="rtb on" id="rtb-s" onclick="setRole('staff')">👨‍🍳 พนักงาน</button>
    <button class="rtb"    id="rtb-a" onclick="setRole('admin')">👑 ผู้ดูแลระบบ</button>
  </div>
  <div class="fg"><label>อีเมล</label>
    <input type="email" id="li-e" value="staff@kitchen.com" onkeydown="if(event.key==='Enter')login()">
  </div>
  <div class="fg"><label>รหัสผ่าน</label>
    <input type="password" id="li-p" value="1234" onkeydown="if(event.key==='Enter')login()">
  </div>
  <p style="font-size:11px;color:var(--mu);margin-bottom:12px;text-align:center">
    staff@kitchen.com / 1234 &nbsp;|&nbsp; admin@kitchen.com / admin
  </p>
  <p id="li-err" style="font-size:12px;color:var(--r);margin-bottom:10px;display:none;text-align:center"></p>
  <button class="btn btn-p btn-fw" id="li-btn" onclick="login()">เข้าสู่ระบบ</button>
</div></div>
</div>

<!-- APP -->
<div id="ap" style="display:none">
<div id="app">
  <div class="sb">
    <div class="sbl">
      <div style="font-size:22px;margin-bottom:5px">🍳</div>
      <div class="sbl-t">ระบบวัตถุดิบครัว</div>
      <div class="sbl-s" id="sb-sub"></div>
    </div>
    <!-- Staff nav -->
    <div id="snav">
      <div class="nl">วัตถุดิบ</div>
      <div class="ni" onclick="go('ing')"  id="n-ing">🥩 รายการวัตถุดิบ</div>
      <div class="ni" onclick="go('rcv')"  id="n-rcv">📦 รับเข้าวัตถุดิบ</div>
      <div class="ni" onclick="go('wd')"   id="n-wd">🛒 เบิกวัตถุดิบ</div>
      <div class="ni" onclick="go('ord')"  id="n-ord">📋 สร้างคำสั่งซื้อ</div>
      <div class="nl" style="margin-top:6px">บัญชีฉัน</div>
      <div class="ni" onclick="go('hist')" id="n-hist">🕐 ประวัติรายการ</div>
      <div class="ni" onclick="go('prof')" id="n-prof">👤 ข้อมูลส่วนตัว</div>
    </div>
    <!-- Admin nav -->
    <div id="anav" style="display:none">
      <div class="nl">ภาพรวม</div>
      <div class="ni" onclick="go('dash')"   id="n-dash">📊 Dashboard</div>
      <div class="nl" style="margin-top:6px">วัตถุดิบ</div>
      <div class="ni" onclick="go('mng')"    id="n-mng">🥩 จัดการวัตถุดิบ</div>
      <div class="ni" onclick="go('cu')"     id="n-cu">🏷️ หมวดหมู่ & หน่วย</div>
      <div class="nl" style="margin-top:6px">รายงาน</div>
      <div class="ni" onclick="go('rpt')"    id="n-rpt">📈 รายงานประวัติ</div>
      <div class="ni" onclick="go('usr')"    id="n-usr">👥 จัดการผู้ใช้</div>
      <div class="ni" onclick="go('appr')"   id="n-appr">✅ อนุมัติสั่งซื้อ</div>
      <div class="nl" style="margin-top:6px">บัญชี</div>
      <div class="ni" onclick="go('prof')"   id="n-prof2">👤 ข้อมูลส่วนตัว</div>
    </div>
    <div class="sbf">
      <div style="display:flex;align-items:center;gap:9px;margin-bottom:10px">
        <div class="av" id="sb-av"></div>
        <div><div style="font-size:12px;font-weight:600" id="sb-nm"></div>
             <div style="font-size:10px;color:var(--mu)" id="sb-rl"></div></div>
      </div>
      <button class="btn btn-sm btn-fw" onclick="logout()">🚪 ออกจากระบบ</button>
    </div>
  </div>

  <div class="main">
    <div class="sec" id="s-ing"></div>
    <div class="sec" id="s-rcv"></div>
    <div class="sec" id="s-wd"></div>
    <div class="sec" id="s-ord"></div>
    <div class="sec" id="s-hist"></div>
    <div class="sec" id="s-prof"></div>
    <div class="sec" id="s-dash"></div>
    <div class="sec" id="s-mng"></div>
    <div class="sec" id="s-cu"></div>
    <div class="sec" id="s-rpt"></div>
    <div class="sec" id="s-usr"></div>
    <div class="sec" id="s-appr"></div>
  </div>
</div>
</div>

<div id="m-det" class="mo" style="display:none"></div>
<div id="m-ing" class="mo" style="display:none"></div>
<div id="m-cu"  class="mo" style="display:none"></div>
<div id="toast"></div>

<script>
const F='index.php';
let USER=null,CATS=[],UNITS=[],INGS=[],selRole='staff',aCat='ทั้งหมด';
let oItems=[{ing:'',qty:'',price:''}];

const $=id=>document.getElementById(id);
async function api(act,data,method='GET',q=''){
  const o={method,credentials:'same-origin',headers:{'Content-Type':'application/json'}};
  if(data) o.body=JSON.stringify(data);
  const r=await fetch(F+'?act='+act+q,o); return r.json();
}
const GET=(a,q='')=>api(a,null,'GET',q);
const POST=(a,d)=>api(a,d,'POST');
const PUT=(a,d,id)=>api(a,d,'PUT','&id='+id);
const DEL=(a,id)=>api(a,null,'DELETE','&id='+id);

function toast(msg,type='ok'){
  const t=$('toast');t.textContent=msg;t.className='on '+type;
  clearTimeout(t._t);t._t=setTimeout(()=>t.className='',2800);
}
function load(){return'<div class="load"><span class="spin"></span> กำลังโหลด...</div>';}
function empty(ic,msg){return`<div class="empty"><div class="ei">${ic}</div><div>${msg}</div></div>`;}
function sbdg(q){const v=parseFloat(q);return v<=2?'<span class="bdg br">ใกล้หมด</span>':v<=3?'<span class="bdg by">น้อย</span>':'<span class="bdg bg">ปกติ</span>';}
function closeM(id){$(id).style.display='none';}

// AUTH
function setRole(r){
  selRole=r;
  $('rtb-s').className='rtb'+(r==='staff'?' on':'');
  $('rtb-a').className='rtb'+(r==='admin'?' on':'');
  $('li-e').value=r==='staff'?'staff@kitchen.com':'admin@kitchen.com';
  $('li-p').value=r==='staff'?'1234':'admin';
}

async function login(){
  const email=$('li-e').value.trim(),pass=$('li-p').value.trim();
  const btn=$('li-btn'),err=$('li-err');
  err.style.display='none';btn.disabled=true;btn.innerHTML='<span class="spin"></span>';
  const r=await POST('login',{email,pass,role:selRole});
  btn.disabled=false;btn.textContent='เข้าสู่ระบบ';
  if(!r.ok){err.textContent=r.msg;err.style.display='block';return;}
  USER=r.data;
  $('lp').style.display='none';$('ap').style.display='block';
  const ini=USER.full_name.split(' ').map(w=>w[0]).join('').slice(0,2);
  $('sb-av').textContent=ini;$('sb-nm').textContent=USER.full_name;
  $('sb-rl').textContent=USER.role==='admin'?'ผู้ดูแลระบบ':'พนักงาน';
  $('sb-sub').textContent=USER.role==='admin'?'👑 Admin':'👨‍🍳 Staff';
  $('snav').style.display=USER.role==='staff'?'block':'none';
  $('anav').style.display=USER.role==='admin'?'block':'none';
  const [cr,ur]=await Promise.all([GET('cats'),GET('units')]);
  CATS=cr.data||[];UNITS=ur.data||[];
  go(USER.role==='admin'?'dash':'ing');
}

async function logout(){
  await GET('logout');USER=null;
  $('ap').style.display='none';$('lp').style.display='block';
}

// NAV
function go(sec,extra={}){
  document.querySelectorAll('.sec').forEach(s=>s.classList.remove('on'));
  document.querySelectorAll('.ni').forEach(n=>n.classList.remove('on'));
  $('s-'+sec)?.classList.add('on');
  const ni=$('n-'+sec)||$('n-'+sec+'2');if(ni)ni.classList.add('on');
  ({ing:()=>renderIng(),rcv:()=>renderRcv(extra.ingId),wd:()=>renderWd(extra.ingId),
    ord:()=>renderOrd(),hist:()=>renderHist(),prof:()=>renderProf(),
    dash:()=>renderDash(),mng:()=>renderMng(),cu:()=>renderCU(),
    rpt:()=>renderRpt(),usr:()=>renderUsr(),appr:()=>renderAppr()})[sec]?.();
}

// ── INGREDIENTS ──────────────────────────────────────────────
async function renderIng(){
  const el=$('s-ing');
  el.innerHTML=`<div class="ph"><div><div class="ph-t">รายการวัตถุดิบ</div><div class="ph-s">ค้นหาและเรียกดูวัตถุดิบ</div></div></div>
    <div class="fg" style="margin-bottom:12px"><div class="sw"><span class="si">🔍</span>
      <input type="text" id="iq" placeholder="ค้นหารหัส / ชื่อ / หมวดหมู่..." oninput="loadIG()">
    </div></div>
    <div class="cp" id="cpills"></div>
    <div id="igrid" class="ig">${load()}</div>`;
  renderPills();await loadIG();
}
function renderPills(){
  const el=$('cpills');if(!el)return;
  el.innerHTML=['ทั้งหมด',...CATS.map(c=>c.name)].map(c=>{
    const lbl=c==='ทั้งหมด'?c:(CATS.find(x=>x.name===c)?.emoji||'')+' '+c;
    return`<button class="cpl${c===aCat?' on':''}" onclick="setCat('${c}')">${lbl}</button>`;
  }).join('');
}
function setCat(c){aCat=c;renderPills();loadIG();}
async function loadIG(){
  const q=($('iq')||{value:''}).value;
  let qstr='';if(q)qstr+='&q='+encodeURIComponent(q);
  if(aCat!=='ทั้งหมด'){const c=CATS.find(x=>x.name===aCat);if(c)qstr+='&cat='+c.category_id;}
  const r=await GET('ings',qstr);INGS=r.data||[];
  const g=$('igrid');if(!g)return;
  if(!INGS.length){g.innerHTML=empty('🔍','ไม่พบวัตถุดิบ');return;}
  g.innerHTML=INGS.map(i=>`
    <div class="ic" onclick="openDet(${i.ingredient_id})">
      <div class="ii">${i.image_url||'📦'}</div>
      <div class="ib">
        <div class="in">${i.name}</div>
        <div class="ic2">${i.code}</div>
        <div class="if">
          <span style="font-family:'Prompt',sans-serif;font-weight:700;font-size:15px">${parseFloat(i.stock_qty)} <small style="font-size:11px;color:var(--mu)">${i.unit_name}</small></span>
          ${sbdg(i.stock_qty)}
        </div>
      </div>
    </div>`).join('');
}

async function openDet(id){
  const r=await GET('ing','&id='+id);const i=r.data;if(!i)return;
  const days=i.days_exp;
  const expC=days===null?'':days<0?'var(--r)':days<=3?'var(--r)':days<=7?'var(--y)':'var(--g)';
  const expTxt=days===null?'-':days<0?`เลยมาแล้ว ${Math.abs(days)} วัน`:days===0?'วันนี้':`อีก ${days} วัน`;
  const el=$('m-det');el.style.display='flex';
  el.innerHTML=`<div class="mb" style="width:580px">
    <div class="mh"><div class="mt">${i.name}</div><button class="mc" onclick="closeM('m-det')">✕</button></div>
    <div style="text-align:center;margin-bottom:16px">
      <div style="font-size:80px;margin-bottom:8px">${i.image_url||'📦'}</div>
      <div style="font-size:12px;color:var(--mu);margin-bottom:6px">${i.code}</div>
      ${sbdg(i.stock_qty)}
    </div>
    <div class="dr"><span class="dl">หมวดหมู่</span><span class="dv">${i.cat_emoji} ${i.cat_name}</span></div>
    <div class="dr"><span class="dl">จำนวนคงเหลือ</span><span class="dv" style="color:var(--ac);font-size:16px">${parseFloat(i.stock_qty)} ${i.unit_name}</span></div>
    <div class="dr"><span class="dl">หน่วยนับ</span><span class="dv">${i.unit_name}</span></div>
    <div class="dr"><span class="dl">ราคา/หน่วย</span><span class="dv">฿${parseFloat(i.price_per_unit).toLocaleString()}</span></div>
    <div class="dr"><span class="dl">มูลค่าคงเหลือ</span><span class="dv">฿${parseFloat(i.total_value||0).toLocaleString()}</span></div>
    <div class="dr"><span class="dl">วันหมดอายุ</span><span class="dv" style="color:${expC}">${i.expiry_date||'-'}${days!==null?' ('+expTxt+')':''}</span></div>
    <div style="font-weight:600;font-size:13px;margin:16px 0 6px">📦 ล็อตคงเหลือ <span style="font-weight:400;color:var(--mu);font-size:12px"></span></div>
    <div id="det-lots">${load()}</div>
    <div class="mf">
      <button class="btn" onclick="closeM('m-det')">ปิด</button>
      <button class="btn btn-p" onclick="closeM('m-det');go('wd',{ingId:${id}})">🛒 เบิกใช้</button>
      <button class="btn" onclick="closeM('m-det');go('rcv',{ingId:${id}})">📦 รับเข้า</button>
    </div>
  </div>`;
  GET('lots','&id='+id).then(r=>{
    const box=$('det-lots');if(!box)return;
    const rows=r.data||[];
    if(!rows.length){box.innerHTML='<div style="font-size:12px;color:var(--mu)">ไม่มีล็อตคงเหลือ</div>';return;}
    box.innerHTML=rows.map((l,n)=>{
      const d=l.days_exp;
      const c=!l.expiry_date?'var(--mu)':d<0?'var(--r)':d<=7?'var(--y)':'var(--g)';
      return`<div class="oir"><div>
        <div style="font-weight:600;font-size:13px">${n===0?'🔸 ตัดก่อน • ':''}เหลือ ${parseFloat(l.qty_remaining)} / ${parseFloat(l.qty_received)} ${i.unit_name}</div>
        <div style="font-size:11px;color:var(--mu)">รับเมื่อ ${(l.received_at||'').slice(0,10)} • ฿${parseFloat(l.price_per_unit).toLocaleString(undefined,{maximumFractionDigits:2})}/${i.unit_name}</div></div>
        <div style="text-align:right;font-size:12px;color:${c}">${l.expiry_date?'หมดอายุ '+l.expiry_date:'ไม่มีวันหมดอายุ'}</div></div>`;
    }).join('');
  });
}

// ── RECEIVE ──────────────────────────────────────────────────
function renderRcv(preId=''){
  const opts=INGS.map(i=>`<option value="${i.ingredient_id}" ${i.ingredient_id==preId?'selected':''}>[${i.code}] ${i.name}</option>`).join('');
  $('s-rcv').innerHTML=`
    <div class="ph"><div><div class="ph-t">รับเข้าวัตถุดิบ</div><div class="ph-s">บันทึกการรับวัตถุดิบเข้าคลัง</div></div></div>
    <div class="card" style="max-width:540px">
      <div class="fg"><label>เลือกวัตถุดิบ</label>
        <select id="ri" onchange="updRcv()"><option value="">-- เลือกวัตถุดิบ --</option>${opts}</select>
      </div>
      <div id="r-info" style="display:none;background:#f9f7f4;border-radius:8px;padding:9px 12px;margin-bottom:12px;font-size:13px">
        สต็อกปัจจุบัน: <strong id="r-cur" style="color:var(--ac)"></strong>
      </div>
      <div class="g2">
        <div class="fg"><label>จำนวนที่รับเข้า</label><input type="number" id="rq" min="0.001" step="0.001" placeholder="0"></div>
        <div class="fg"><label>ราคา/หน่วย (บาท)</label><input type="number" id="rp" min="0" step="0.01" placeholder="0.00"></div>
      </div>
      <div class="g2">
        <div class="fg"><label>วันหมดอายุ</label><input type="date" id="re"></div>
        <div class="fg"><label>สาเหตุ / หมายเหตุ</label><input type="text" id="rn" placeholder="เช่น ซื้อจากตลาด"></div>
      </div>
      <button class="btn btn-p" id="r-btn" onclick="submitRcv()">📦 บันทึกการรับเข้า</button>
    </div>`;
  if(preId)updRcv();
}
function updRcv(){
  const id=$('ri')?.value;const info=$('r-info');if(!info)return;
  if(!id){info.style.display='none';return;}
  const ing=INGS.find(i=>i.ingredient_id==id);
  if(ing){$('r-cur').textContent=parseFloat(ing.stock_qty)+' '+ing.unit_name;info.style.display='block';}
}
async function submitRcv(){
  const ing=$('ri').value,qty=parseFloat($('rq').value);
  if(!ing||!qty||qty<=0){toast('กรุณากรอกข้อมูลให้ครบ','er');return;}
  const btn=$('r-btn');btn.disabled=true;btn.innerHTML='<span class="spin"></span>';
  const r=await POST('receive',{ing_id:parseInt(ing),qty,price:parseFloat($('rp').value)||0,
    expiry_date:$('re').value||null,reason:$('rn').value||null});
  btn.disabled=false;btn.textContent='📦 บันทึกการรับเข้า';
  if(r.ok){
    toast('✅ บันทึกการรับเข้าสำเร็จ');
    ['ri','rq','rp','re','rn'].forEach(id=>{const e=$(id);if(e)e.value='';});
    $('r-info').style.display='none';
    const ri=await GET('ings');INGS=ri.data||[];
  } else toast(r.msg,'er');
}

// ── WITHDRAW ─────────────────────────────────────────────────
function renderWd(preId=''){
  const opts=INGS.map(i=>`<option value="${i.ingredient_id}" ${i.ingredient_id==preId?'selected':''}>[${i.code}] ${i.name}</option>`).join('');
  $('s-wd').innerHTML=`
    <div class="ph">
      <div><div class="ph-t">เบิกวัตถุดิบ</div><div class="ph-s">เลือกวัตถุดิบและระบุจำนวนที่ต้องการเบิก</div></div>
      <button class="btn btn-sm" onclick="go('ing')">← กลับรายการวัตถุดิบ</button>
    </div>
    <div class="card" style="max-width:540px">
      <div class="fg"><label>เลือกวัตถุดิบ</label>
        <select id="wi" onchange="updWd()"><option value="">-- เลือกวัตถุดิบ --</option>${opts}</select>
      </div>
      <div id="w-info" style="display:none;background:#f9f7f4;border-radius:8px;padding:9px 12px;margin-bottom:12px;font-size:13px">
        สต็อกคงเหลือ: <strong id="w-cur" style="color:var(--ac)"></strong>
      </div>
      <div class="fg"><label>จำนวนที่เบิก</label><input type="number" id="wq" min="0.001" step="0.001" placeholder="0"></div>
      <div class="fg"><label>เหตุผลการเบิก</label>
        <select id="wr">
          <option value="">-- เลือกเหตุผล --</option>
          <option>ใช้ปรุงอาหาร</option>
          <option>ทำหก</option><option>ของเสีย</option><option>ทิ้ง</option>
        </select>
      </div>
      <div class="fg"><label>หมายเหตุเพิ่มเติม</label>
        <textarea id="wn" placeholder="รายละเอียดเพิ่มเติม (ถ้ามี)"></textarea>
      </div>
      <button class="btn btn-p" id="w-btn" onclick="submitWd()">🛒 บันทึกการเบิก</button>
    </div>`;
  if(preId)updWd();
}
function updWd(){
  const id=$('wi')?.value;const info=$('w-info');if(!info)return;
  if(!id){info.style.display='none';return;}
  const ing=INGS.find(i=>i.ingredient_id==id);
  if(ing){
    const color=parseFloat(ing.stock_qty)<=2?'var(--r)':parseFloat(ing.stock_qty)<=5?'var(--y)':'var(--ac)';
    $('w-cur').innerHTML=`<span style="color:${color}">${parseFloat(ing.stock_qty)} ${ing.unit_name}</span>`;
    info.style.display='block';
  }
}
async function submitWd(){
  const ing=$('wi').value,qty=parseFloat($('wq').value),reason=$('wr').value,note=$('wn').value;
  if(!ing||!qty||qty<=0||!reason){toast('กรุณากรอกข้อมูลให้ครบ','er');return;}
  const btn=$('w-btn');btn.disabled=true;btn.innerHTML='<span class="spin"></span>';
  const r=await POST('withdraw',{ing_id:parseInt(ing),qty,reason,note:note||null});
  btn.disabled=false;btn.textContent='🛒 บันทึกการเบิก';
  if(r.ok){
    toast('✅ บันทึกการเบิกออกสำเร็จ');
    const ri=await GET('ings');INGS=ri.data||[];
    setTimeout(()=>go('ing'),1200);
  } else toast(r.msg,'er');
}

// ── ORDER ────────────────────────────────────────────────────
function renderOrd(){
  $('s-ord').innerHTML=`
    <div class="ph"><div><div class="ph-t">สร้างคำสั่งซื้อ</div><div class="ph-s">ส่งให้ผู้ดูแลระบบพิจารณา</div></div></div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;align-items:start">
      <div class="card">
        <div class="ct">📋 รายการที่ต้องการสั่งซื้อ</div>
        <div id="oi-list"></div>
        <button class="btn btn-sm" onclick="addOI()" style="margin-bottom:12px">+ เพิ่มรายการ</button>
        <div class="fg"><label>หมายเหตุ / เหตุผล</label>
          <textarea id="on" placeholder="เช่น สต็อกใกล้หมด, ต้องการเร่งด่วน"></textarea>
        </div>
        <button class="btn btn-p btn-fw" id="o-btn" onclick="submitOrd()">📤 ส่งคำสั่งซื้อ</button>
      </div>
      <div>
        <div style="font-weight:600;margin-bottom:10px;font-size:14px">📜 คำสั่งซื้อของฉัน</div>
        <div id="my-o">${load()}</div>
      </div>
    </div>`;
  oItems=[{ing:'',qty:'',price:''}];renderOIs();loadMyO();
}
function renderOIs(){
  const el=$('oi-list');if(!el)return;
  el.innerHTML=oItems.map((item,i)=>`
    <div style="background:#f9f7f4;border-radius:10px;padding:12px;margin-bottom:10px">
      <div style="display:flex;justify-content:space-between;margin-bottom:8px">
        <span style="font-size:12px;font-weight:600;color:var(--mu)">รายการที่ ${i+1}</span>
        ${oItems.length>1?`<button onclick="removeOI(${i})" style="background:none;border:none;color:var(--r);cursor:pointer;font-size:16px">✕</button>`:''}
      </div>
      <div class="fg"><label>วัตถุดิบ</label>
        <select onchange="oItems[${i}].ing=this.value">
          <option value="">-- เลือก --</option>
          ${INGS.map(x=>`<option value="${x.ingredient_id}" ${x.ingredient_id==item.ing?'selected':''}>[${x.code}] ${x.name}</option>`).join('')}
        </select>
      </div>
      <div class="g2">
        <div class="fg"><label>จำนวน</label><input type="number" min="0.001" step="0.001" value="${item.qty}" onchange="oItems[${i}].qty=this.value" placeholder="0"></div>
        <div class="fg"><label>ราคารวมโดยประมาณ (฿)</label><input type="number" min="0" value="${item.price}" onchange="oItems[${i}].price=this.value" placeholder="0"></div>
      </div>
    </div>`).join('');
}
function addOI(){oItems.push({ing:'',qty:'',price:''});renderOIs();}
function removeOI(i){oItems.splice(i,1);if(!oItems.length)oItems=[{ing:'',qty:'',price:''}];renderOIs();}
async function loadMyO(){
  const r=await GET('orders');const el=$('my-o');if(!el)return;
  const orders=r.data||[];
  const sc={pending:'by',approved:'bg',rejected:'br'};
  const sl={pending:'รออนุมัติ',approved:'อนุมัติแล้ว',rejected:'ปฏิเสธ'};
  if(!orders.length){el.innerHTML=`<div class="card">${empty('📋','ยังไม่มีคำสั่งซื้อ')}</div>`;return;}
  el.innerHTML=orders.map(o=>`
    <div class="card" style="margin-bottom:10px">
      <div style="display:flex;justify-content:space-between;margin-bottom:8px">
        <div style="font-size:12px;color:var(--mu)">#${o.order_id} • ${(o.requested_at||'').slice(0,16)}</div>
        <span class="bdg ${sc[o.status]}">${sl[o.status]}</span>
      </div>
      ${(o.items||[]).map(it=>`<div class="oir"><span>${it.ing_img||'📦'} ${it.ing_name}</span><span style="color:var(--mu);font-size:12px">${parseFloat(it.qty)} ${it.unit_name} • ฿${(parseFloat(it.qty)*parseFloat(it.price_per_unit)).toLocaleString(undefined,{maximumFractionDigits:2})}</span></div>`).join('')}
      ${o.note?`<div style="font-size:12px;color:var(--mu);margin-top:6px">💬 ${o.note}</div>`:''}
    </div>`).join('');
}
async function submitOrd(){
  const valid=oItems.filter(x=>x.ing&&parseFloat(x.qty)>0);
  if(!valid.length){toast('กรุณาระบุรายการอย่างน้อย 1 รายการ','er');return;}
  const btn=$('o-btn');btn.disabled=true;btn.innerHTML='<span class="spin"></span>';
  const r=await POST('create_order',{items:valid.map(x=>({ing_id:parseInt(x.ing),qty:parseFloat(x.qty),total:parseFloat(x.price)||0})),note:$('on')?.value||''});
  btn.disabled=false;btn.textContent='📤 ส่งคำสั่งซื้อ';
  if(r.ok){toast('✅ ส่งคำสั่งซื้อสำเร็จ');oItems=[{ing:'',qty:'',price:''}];renderOIs();if($('on'))$('on').value='';loadMyO();}
  else toast(r.msg,'er');
}

// ── HISTORY ──────────────────────────────────────────────────
async function renderHist(){
  const el=$('s-hist');
  el.innerHTML=`<div class="ph"><div><div class="ph-t">ประวัติรายการของฉัน</div></div></div><div class="card" id="hl">${load()}</div>`;
  const r=await GET('txns');const rows=r.data?.items||[];
  const list=$('hl');if(!list)return;
  if(!rows.length){list.innerHTML=empty('📋','ยังไม่มีประวัติ');return;}
  list.innerHTML=rows.map(t=>{
    const isIn=t.txn_type==='receive';
    return`<div class="hi">
      <div class="hic" style="background:${isIn?'#dcfce7':'#fee2e2'}">${isIn?'📦':'🛒'}</div>
      <div style="flex:1">
        <div style="font-weight:600;font-size:14px">${t.ing_name}</div>
        <div style="font-size:12px;color:var(--mu)">${(t.txn_date||'').slice(0,16)} • ${t.reason||'-'}</div>
      </div>
      <div style="text-align:right">
        <div style="font-weight:700;font-size:15px;color:${isIn?'var(--g)':'var(--r)'}">
          ${isIn?'+':'-'}${parseFloat(t.qty)} ${t.unit_name}
        </div>
        <span class="bdg ${isIn?'bg':'br'}">${isIn?'รับเข้า':'เบิกออก'}</span>
      </div>
    </div>`;
  }).join('');
}

// ── PROFILE ──────────────────────────────────────────────────
function renderProf(){
  const u=USER;const ini=u.full_name.split(' ').map(w=>w[0]).join('').slice(0,2);
  $('s-prof').innerHTML=`
    <div class="ph"><div class="ph-t">ข้อมูลส่วนตัว</div></div>
    <div class="card" style="max-width:460px">
      <div style="text-align:center;margin-bottom:18px">
        <div style="width:60px;height:60px;border-radius:50%;background:linear-gradient(135deg,var(--ac),var(--ac2));
          display:flex;align-items:center;justify-content:center;font-size:20px;font-weight:700;color:#fff;margin:0 auto 10px">${ini}</div>
        <div style="font-size:16px;font-weight:600">${u.full_name}</div>
        <div style="font-size:12px;color:var(--mu)">${u.role==='admin'?'ผู้ดูแลระบบ':'พนักงาน'}</div>
      </div>
      <div style="height:1px;background:var(--bd);margin:0 0 14px"></div>
      <div class="fg"><label>ชื่อ - นามสกุล</label><input type="text" id="pn" value="${u.full_name}"></div>
      <div class="fg"><label>อีเมล</label><input type="email" id="pe" value="${u.email}"></div>
      <div class="fg"><label>เบอร์โทรศัพท์</label><input type="tel" id="pp" value="${u.phone||''}"></div>
      <div style="height:1px;background:var(--bd);margin:0 0 12px"></div>
      <div style="font-weight:600;font-size:13px;margin-bottom:10px">เปลี่ยนรหัสผ่าน</div>
      <div class="fg"><label>รหัสผ่านใหม่ (เว้นว่างถ้าไม่เปลี่ยน)</label>
        <input type="password" id="ppw" placeholder="รหัสผ่านใหม่">
      </div>
      <button class="btn btn-p" id="pf-btn" onclick="saveProf()">💾 บันทึกข้อมูล</button>
    </div>`;
}
async function saveProf(){
  const data={name:$('pn').value,email:$('pe').value,phone:$('pp').value};
  const pw=$('ppw').value;if(pw)data.pass=pw;
  const btn=$('pf-btn');btn.disabled=true;btn.innerHTML='<span class="spin"></span>';
  const r=await POST('profile',data);
  btn.disabled=false;btn.textContent='💾 บันทึกข้อมูล';
  if(r.ok){USER=r.data;$('sb-nm').textContent=USER.full_name;toast('✅ บันทึกสำเร็จ');}
  else toast(r.msg,'er');
}

// ── DASHBOARD ────────────────────────────────────────────────
async function renderDash(){
  const el=$('s-dash');
  el.innerHTML=`<div class="ph"><div><div class="ph-t">Dashboard</div><div class="ph-s">ภาพรวมสถานะวัตถุดิบ</div></div></div>${load()}`;
  const r=await GET('dash');if(!r.ok)return;
  const {stats,low,exp}=r.data;
  el.innerHTML=`<div class="ph"><div><div class="ph-t">Dashboard</div><div class="ph-s">ภาพรวมสถานะวัตถุดิบ</div></div></div>
  <div class="g4" style="margin-bottom:18px">
    <div class="sc"><div style="font-size:24px;margin-bottom:6px">📦</div><div class="sv" style="color:var(--bl)">${stats.total||0}</div><div class="sl">รายการวัตถุดิบ</div></div>
    <div class="sc"><div style="font-size:24px;margin-bottom:6px">💰</div><div class="sv" style="color:var(--g)">฿${parseFloat(stats.val||0).toLocaleString()}</div><div class="sl">มูลค่าคงเหลือรวม</div></div>
    <div class="sc"><div style="font-size:24px;margin-bottom:6px">⚠️</div><div class="sv" style="color:var(--y)">${stats.low_stock||0}</div><div class="sl">ใกล้หมด (≤5)</div></div>
    <div class="sc"><div style="font-size:24px;margin-bottom:6px">📅</div><div class="sv" style="color:var(--r)">${stats.exp_soon||0}</div><div class="sl">ใกล้หมดอายุ (7 วัน)</div></div>
  </div>
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
    <div class="card"><div class="ct">⚠️ วัตถุดิบใกล้หมด</div>
      ${low.length?low.map(i=>{const pct=Math.min(100,Math.round(parseFloat(i.stock_qty)/10*100));
        return`<div class="bar-wrap"><div class="bar-lbl"><span>${i.image_url||'📦'} ${i.name}</span>
          <span style="color:${parseFloat(i.stock_qty)<=2?'var(--r)':'var(--y)'}">
          ${parseFloat(i.stock_qty)} ${i.unit_name}</span></div>
          <div class="bar"><div class="bar-fill" style="width:${pct}%;background:${parseFloat(i.stock_qty)<=2?'var(--r)':'var(--y)'}"></div></div>
        </div>`;}).join(''):'<div style="color:var(--mu);font-size:13px">ไม่มีวัตถุดิบใกล้หมด ✅</div>'}
    </div>
    <div class="card"><div class="ct">📅 วัตถุดิบใกล้หมดอายุ (7 วัน)</div>
      ${exp.length?exp.map(i=>{const d=parseInt(i.days);
        return`<div class="dr"><span>${i.image_url||'📦'} ${i.name}</span>
          <span class="bdg ${d<=3?'br':'by'}">อีก ${d} วัน</span></div>`;}).join('')
        :'<div style="color:var(--mu);font-size:13px">ไม่มีวัตถุดิบใกล้หมดอายุ ✅</div>'}
    </div>
  </div>`;
}

// ── MANAGE INGREDIENTS ───────────────────────────────────────
async function renderMng(){
  $('s-mng').innerHTML=`
    <div class="ph"><div><div class="ph-t">จัดการวัตถุดิบ</div></div>
      <button class="btn btn-p" onclick="openIngM()">➕ เพิ่มวัตถุดิบ</button></div>
    <div class="fg" style="max-width:300px;margin-bottom:12px"><div class="sw"><span class="si">🔍</span>
      <input type="text" id="mq" placeholder="ค้นหา..." oninput="loadMng()">
    </div></div>
    <div id="mtb" class="tw">${load()}</div>`;
  loadMng();
}
async function loadMng(){
  const q=($('mq')||{value:''}).value;
  const r=await GET('ings',q?'&q='+encodeURIComponent(q):'');
  window.MNG=r.data||[];
  $('mtb').innerHTML=`<table><thead><tr>
    <th>รูป</th><th>รหัส</th><th>ชื่อ</th><th>หมวดหมู่</th><th>หน่วย</th><th>สต็อก</th><th>ราคา/หน่วย (ตอนรับเข้า)</th><th>จัดการ</th>
  </tr></thead><tbody>${(r.data||[]).map(i=>`
    <tr><td style="font-size:22px">${i.image_url||'📦'}</td>
    <td><code style="font-size:11px;color:var(--mu)">${i.code}</code></td>
    <td><strong>${i.name}</strong></td>
    <td>${i.cat_emoji} ${i.cat_name}</td>
    <td>${i.unit_name}</td>
    <td><strong>${parseFloat(i.stock_qty)}</strong></td>
    <td>฿${parseFloat(i.last_receive_price??i.price_per_unit).toLocaleString()}</td>
    <td><div style="display:flex;gap:5px">
      <button class="btn btn-sm" onclick="openIngM(${i.ingredient_id})">✏️ แก้ไข</button>
      <button class="btn btn-sm btn-r" onclick="delIng(${i.ingredient_id})">🗑️ ลบ</button>
    </div></td></tr>`).join('')}</tbody></table>`;
}
async function delIng(id){
  const i=(window.MNG||[]).find(x=>x.ingredient_id==id);
  const stk=i?parseFloat(i.stock_qty):0;
  if(!confirm('ลบวัตถุดิบ "'+(i?i.name:'')+'" ?'+(stk>0?'\nยังมีสต็อกเหลือ '+stk+' '+i.unit_name:'')))return;
  const r=await DEL('del_ing',id);
  if(r.ok){toast('🗑️ ลบแล้ว');const ri=await GET('ings');INGS=ri.data||[];loadMng();}
  else toast(r.msg,'er');
}

const FLD={code:['รหัส'],name:['ชื่อ'],category_id:['หมวดหมู่','cat_name'],unit_id:['หน่วยนับ','unit_name'],
  stock_qty:['สต็อก'],price_per_unit:['ราคา/หน่วย'],expiry_date:['วันหมดอายุ'],image_url:['รูป'],status:['สถานะ']};
function diffLog(l){
  let o={},n={};
  try{o=JSON.parse(l.old_values||'{}')||{}}catch(e){}
  try{n=JSON.parse(l.new_values||'{}')||{}}catch(e){}
  const ch=[];
  for(const k in FLD){
    if(!(k in n))continue;
    const [lb,dk]=FLD[k];
    if(String(o[k]??'')===String(n[k]??''))continue;
    const ov=dk?o[dk]:o[k],nv=dk?n[dk]:n[k];
    ch.push(`<b>${lb}</b>: ${ov??'-'} → ${nv??'-'}`);
  }
  return {ch,note:n.edit_note||''};
}

function openIngM(id=null){
  const el=$('m-ing');el.style.display='flex';
  el._oldStock=undefined;
  el.innerHTML=`<div class="mb" style="width:640px"><div class="mh">
    <div class="mt">${id?'✏️ แก้ไขวัตถุดิบ':'➕ เพิ่มวัตถุดิบใหม่'}</div>
    <button class="mc" onclick="closeM('m-ing')">✕</button></div>
    ${id?`<div id="fi-info" style="background:#f9f7f4;border-radius:10px;padding:10px 14px;margin-bottom:14px;font-size:12px;color:var(--mu)">${load()}</div>`:''}
    <div class="fg"><label>รูปภาพ (Emoji หรือ URL)</label><input type="text" id="fi-img" placeholder="🥩"></div>
    <div class="g2">
      <div class="fg"><label>รหัสวัตถุดิบ</label><input type="text" id="fi-code" placeholder="ING001"></div>
      <div class="fg"><label>ชื่อวัตถุดิบ</label><input type="text" id="fi-name"></div>
    </div>
    <div class="g2">
      <div class="fg"><label>หมวดหมู่</label><select id="fi-cat">
        <option value="">-- เลือก --</option>${CATS.map(c=>`<option value="${c.category_id}">${c.emoji} ${c.name}</option>`).join('')}</select></div>
      <div class="fg"><label>หน่วยนับ</label><select id="fi-unit">
        <option value="">-- เลือก --</option>${UNITS.map(u=>`<option value="${u.unit_id}">${u.name}</option>`).join('')}</select></div>
    </div>
    <div class="g2">
      <div class="fg"><label>${id?'สต็อกคงเหลือ (เพิ่ม = สร้างล็อตใหม่ / ลด = ตัดล็อตที่หมดอายุก่อน)':'จำนวนเริ่มต้น'}</label>
        <input type="number" id="fi-stock" value="0" min="0" step="0.001" oninput="stkDiff()"></div>
      <div class="fg"><label>ราคา/หน่วย (฿)</label><input type="number" id="fi-price" value="0" min="0" step="0.01"></div>
    </div>
    <div id="fi-diff" style="font-size:12px;margin:-6px 0 10px;display:none"></div>
    <div class="fg"><label>${id?'วันหมดอายุของยอดที่เพิ่ม (ใช้เมื่อเพิ่มสต็อกเท่านั้น)':'วันหมดอายุ'}</label><input type="date" id="fi-exp"></div>
    ${id?`<div class="fg"><label>เหตุผลการแก้ไข (บันทึกในประวัติ)</label>
      <textarea id="fi-note" placeholder="เช่น นับสต็อกจริงแล้วไม่ตรง, ปรับราคาตามซัพพลายเออร์"></textarea></div>
      <div style="font-weight:600;font-size:13px;margin:6px 0">🕐 ประวัติการแก้ไขล่าสุด</div>
      <div id="fi-log" style="max-height:170px;overflow-y:auto">${load()}</div>`:''}
    <div class="mf">
      <button class="btn" onclick="closeM('m-ing')">ยกเลิก</button>
      <button class="btn btn-p" id="fi-btn" onclick="saveIng(${id||'null'})">💾 บันทึก</button>
    </div>
  </div>`;
  if(!id)return;
  GET('ing','&id='+id).then(r=>{const i=r.data;if(!i)return;
    el._oldStock=parseFloat(i.stock_qty);
    $('fi-img').value=i.image_url||'';$('fi-code').value=i.code;$('fi-name').value=i.name;
    $('fi-cat').value=i.category_id;$('fi-unit').value=i.unit_id;
    $('fi-stock').value=parseFloat(i.stock_qty);$('fi-price').value=parseFloat(i.price_per_unit);
    $('fi-info').innerHTML=`สร้างเมื่อ: ${(i.created_at||'-').slice(0,16)} &nbsp;•&nbsp; แก้ไขล่าสุด: ${(i.updated_at||'-').slice(0,16)}
      &nbsp;•&nbsp; มูลค่าคงเหลือ: <b>฿${parseFloat(i.total_value||0).toLocaleString()}</b>
      &nbsp;•&nbsp; หมดอายุใกล้สุด: <b>${i.expiry_date||'-'}</b>`;
  });
  GET('ing_log','&id='+id).then(r=>{
    const box=$('fi-log');if(!box)return;
    const rows=r.data||[];
    if(!rows.length){box.innerHTML='<div style="font-size:12px;color:var(--mu)">ยังไม่มีประวัติ</div>';return;}
    box.innerHTML=rows.map(l=>{
      const d=diffLog(l);
      const when=(l.changed_at||l.created_at||'').slice(0,16);
      return`<div style="padding:7px 0;border-bottom:1px solid var(--bd);font-size:12px">
        <div style="color:var(--mu)">${when} • ${l.by_name||'-'} • ${l.action}</div>
        ${d.ch.length?`<div>${d.ch.join('<br>')}</div>`:''}
        ${d.note?`<div style="color:var(--ac)">💬 ${d.note}</div>`:''}</div>`;
    }).join('');
  });
}
function stkDiff(){
  const el=$('m-ing'),box=$('fi-diff');
  if(el._oldStock===undefined||!box)return;
  const nv=parseFloat($('fi-stock').value)||0,d=nv-el._oldStock;
  if(Math.abs(d)<0.0001){box.style.display='none';return;}
  box.style.display='block';
  box.innerHTML=`<span style="color:${d>0?'var(--g)':'var(--r)'}">สต็อกจะเปลี่ยน ${el._oldStock} → ${nv} (${d>0?'+':''}${+d.toFixed(3)})</span>`;
}
async function saveIng(id){
  const data={image_url:$('fi-img').value,code:$('fi-code').value.trim(),name:$('fi-name').value.trim(),
    category_id:$('fi-cat').value,unit_id:$('fi-unit').value,
    stock_qty:$('fi-stock').value,price_per_unit:$('fi-price').value,expiry_date:$('fi-exp').value||null};
  const editing=id&&id!=='null';
  if(editing){data.edit_note=$('fi-note').value;}
  const btn=$('fi-btn');btn.disabled=true;btn.innerHTML='<span class="spin"></span>';
  const r=editing?await PUT('save_ing',data,id):await POST('save_ing',data);
  btn.disabled=false;btn.textContent='💾 บันทึก';
  if(r.ok){closeM('m-ing');toast('✅ บันทึกสำเร็จ');const ri=await GET('ings');INGS=ri.data||[];loadMng();}
  else toast(r.msg,'er');
}

// ── CATEGORIES & UNITS ───────────────────────────────────────
async function renderCU(){
  $('s-cu').innerHTML=`<div class="ph"><div class="ph-t">หมวดหมู่ & หน่วยนับ</div></div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
      <div class="card">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
          <div class="ct" style="margin:0;padding:0;border:none">🏷️ หมวดหมู่วัตถุดิบ</div>
          <button class="btn btn-p btn-sm" onclick="openCatM()">+ เพิ่ม</button>
        </div><div id="cl"></div>
      </div>
      <div class="card">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
          <div class="ct" style="margin:0;padding:0;border:none">📏 หน่วยนับ</div>
          <button class="btn btn-p btn-sm" onclick="openUnitM()">+ เพิ่ม</button>
        </div><div id="ul"></div>
      </div>
    </div>`;
  renderCL();renderUL();
}
function renderCL(){
  const el=$('cl');if(!el)return;const rs='display:flex;align-items:center;justify-content:space-between;padding:9px 0;border-bottom:1px solid var(--bd)';
  el.innerHTML=CATS.map(c=>`<div style="${rs}"><span><span style="font-size:18px">${c.emoji}</span> ${c.name}</span>
    <div style="display:flex;gap:5px">
      <button class="btn btn-sm" onclick="openCatM(${c.category_id})">✏️</button>
      <button class="btn btn-sm btn-r" onclick="delCat(${c.category_id})">🗑️</button>
    </div></div>`).join('');
}
function renderUL(){
  const el=$('ul');if(!el)return;const rs='display:flex;align-items:center;justify-content:space-between;padding:9px 0;border-bottom:1px solid var(--bd)';
  el.innerHTML=UNITS.map(u=>`<div style="${rs}"><span>📏 ${u.name}</span>
    <div style="display:flex;gap:5px">
      <button class="btn btn-sm" onclick="openUnitM(${u.unit_id})">✏️</button>
      <button class="btn btn-sm btn-r" onclick="delUnit(${u.unit_id})">🗑️</button>
    </div></div>`).join('');
}
function openCatM(id=null){
  const cat=id?CATS.find(c=>c.category_id===id):null;
  const el=$('m-cu');el.style.display='flex';
  el.innerHTML=`<div class="mb mb-sm"><div class="mh">
    <div class="mt">${cat?'แก้ไขหมวดหมู่':'เพิ่มหมวดหมู่'}</div>
    <button class="mc" onclick="closeM('m-cu')">✕</button></div>
    <div class="fg"><label>ชื่อหมวดหมู่</label><input type="text" id="cn" value="${cat?.name||''}" placeholder="เช่น เนื้อสัตว์"></div>
    <div class="fg"><label>Emoji</label><input type="text" id="ce" value="${cat?.emoji||''}" placeholder="🥩"></div>
    <div class="mf"><button class="btn" onclick="closeM('m-cu')">ยกเลิก</button>
      <button class="btn btn-p" onclick="saveCat(${id||'null'})">💾 บันทึก</button></div>
  </div>`;
}
async function saveCat(id){
  const r=id&&id!=='null'?await PUT('save_cat',{name:$('cn').value,emoji:$('ce').value||'📦'},id)
                          :await POST('save_cat',{name:$('cn').value,emoji:$('ce').value||'📦'});
  if(r.ok){closeM('m-cu');const rc=await GET('cats');CATS=rc.data||[];renderCL();toast('✅ บันทึกสำเร็จ');}
  else toast(r.msg,'er');
}
async function delCat(id){
  if(!confirm('ลบหมวดหมู่นี้?'))return;
  const r=await DEL('del_cat',id);
  if(r.ok){const rc=await GET('cats');CATS=rc.data||[];renderCL();toast('🗑️ ลบแล้ว');}
  else toast(r.msg,'er');
}
function openUnitM(id=null){
  const u=id?UNITS.find(x=>x.unit_id===id):null;
  const el=$('m-cu');el.style.display='flex';
  el.innerHTML=`<div class="mb mb-sm"><div class="mh">
    <div class="mt">${u?'แก้ไขหน่วยนับ':'เพิ่มหน่วยนับ'}</div>
    <button class="mc" onclick="closeM('m-cu')">✕</button></div>
    <div class="fg"><label>หน่วยนับ</label><input type="text" id="un" value="${u?.name||''}" placeholder="เช่น กิโลกรัม"></div>
    <div class="mf"><button class="btn" onclick="closeM('m-cu')">ยกเลิก</button>
      <button class="btn btn-p" onclick="saveUnit(${id||'null'})">💾 บันทึก</button></div>
  </div>`;
}
async function saveUnit(id){
  const r=id&&id!=='null'?await PUT('save_unit',{name:$('un').value},id):await POST('save_unit',{name:$('un').value});
  if(r.ok){closeM('m-cu');const ru=await GET('units');UNITS=ru.data||[];renderUL();toast('✅ บันทึกสำเร็จ');}
  else toast(r.msg,'er');
}
async function delUnit(id){
  if(!confirm('ลบหน่วยนับนี้?'))return;
  const r=await DEL('del_unit',id);
  if(r.ok){const ru=await GET('units');UNITS=ru.data||[];renderUL();toast('🗑️ ลบแล้ว');}
  else toast(r.msg,'er');
}

// ── REPORT ───────────────────────────────────────────────────
async function renderRpt(){
  $('s-rpt').innerHTML=`<div class="ph"><div class="ph-t">รายงานประวัติการรับเข้า / เบิกออก</div></div>
    <div class="card" style="margin-bottom:14px">
      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
        <div class="fg" style="margin:0"><label>ประเภท</label><select id="rt" style="width:140px">
          <option value="">ทั้งหมด</option><option value="receive">รับเข้า</option><option value="withdraw">เบิกออก</option>
        </select></div>
        <div class="fg" style="margin:0"><label>ตั้งแต่</label><input type="date" id="rf" style="width:155px"></div>
        <div class="fg" style="margin:0"><label>ถึง</label><input type="date" id="rt2" style="width:155px"></div>
        <button class="btn btn-p btn-sm" onclick="loadRpt()">🔍 ค้นหา</button>
        <button class="btn btn-sm" onclick="$('rt').value='';$('rf').value='';$('rt2').value='';loadRpt()">รีเซ็ต</button>
      </div>
    </div>
    <div class="g3" style="margin-bottom:14px" id="rp-st">${load()}</div>
    <div class="tw" id="rp-tb">${load()}</div>`;
  loadRpt();
}
async function loadRpt(){
  const t=($('rt')||{value:''}).value,f=($('rf')||{value:''}).value,to=($('rt2')||{value:''}).value;
  let q='';if(t)q+='&type='+t;if(f)q+='&from='+f;if(to)q+='&to='+to;
  const r=await GET('txns',q);const d=r.data||{};
  $('rp-st').innerHTML=`
    <div class="sc"><div class="sl">ต้นทุนซื้อเข้ารวม</div><div class="sv" style="color:var(--g)">฿${parseFloat(d.buy||0).toLocaleString()}</div></div>
    <div class="sc"><div class="sl">มูลค่าความเสียหาย (ของเสีย/ทิ้ง)</div><div class="sv" style="color:var(--r)">฿${parseFloat(d.dmg||0).toLocaleString()}</div></div>
    <div class="sc"><div class="sl">รายการทั้งหมด</div><div class="sv">${d.count||0}</div></div>`;
  const rows=d.items||[];
  $('rp-tb').innerHTML=`<table><thead><tr>
    <th>วันที่/เวลา</th><th>ประเภท</th><th>วัตถุดิบ</th><th>จำนวน</th><th>ราคารวม</th><th>เหตุผล</th><th>ผู้ทำรายการ</th>
  </tr></thead><tbody>${rows.map(t=>{const isIn=t.txn_type==='receive';return`
    <tr><td style="font-size:12px;color:var(--mu)">${(t.txn_date||'').slice(0,16)}</td>
    <td><span class="bdg ${isIn?'bg':'br'}">${isIn?'รับเข้า':'เบิกออก'}</span></td>
    <td>${t.ing_img||'📦'} ${t.ing_name}</td>
    <td style="color:${isIn?'var(--g)':'var(--r)'};font-weight:600">${isIn?'+':'-'}${parseFloat(t.qty)} ${t.unit_name}</td>
    <td>฿${parseFloat(t.total_price||0).toLocaleString()}</td>
    <td style="color:var(--mu)">${t.reason||'-'}</td>
    <td>${t.performed_name}</td></tr>`;}).join('')}</tbody></table>`;
}

// ── USERS ────────────────────────────────────────────────────
async function renderUsr(){
  const el=$('s-usr');
  el.innerHTML=`<div class="ph"><div><div class="ph-t">จัดการผู้ใช้งาน</div><div class="ph-s">อนุมัติและจัดการสิทธิ์พนักงาน</div></div></div><div id="ul2">${load()}</div>`;
  const r=await GET('users');const rows=r.data||[];
  const sc={active:'bg',pending:'by',suspended:'br'};
  const sl={active:'ใช้งาน',pending:'รออนุมัติ',suspended:'ถูกระงับ'};
  $('ul2').innerHTML=rows.map(u=>{
    const ini=u.full_name.split(' ').map(w=>w[0]).join('').slice(0,2);
    return`<div class="card" style="display:flex;align-items:center;gap:12px;padding:13px 16px;margin-bottom:8px">
      <div class="av">${ini}</div>
      <div style="flex:1"><div style="font-weight:600;font-size:14px">${u.full_name}</div>
        <div style="font-size:12px;color:var(--mu)">${u.email} • ${u.phone||'-'}</div></div>
      <div style="display:flex;align-items:center;gap:7px">
        <span class="bdg ${sc[u.status]}">${sl[u.status]}</span>
        ${u.status==='pending'?`<button class="btn btn-g btn-sm" onclick="setUSt(${u.user_id},'active')">✅ อนุมัติ</button>`:''}
        ${u.status==='active'?`<button class="btn btn-y btn-sm" onclick="setUSt(${u.user_id},'suspended')">⏸ ระงับ</button>`:''}
        ${u.status==='suspended'?`<button class="btn btn-g btn-sm" onclick="setUSt(${u.user_id},'active')">▶ คืนสิทธิ์</button>`:''}
        <button class="btn btn-r btn-sm" onclick="delUsr(${u.user_id})">🗑️</button>
      </div>
    </div>`;}).join('');
}
async function setUSt(id,status){
  const r=await PUT('save_user',{status},id);
  if(r.ok){toast('✅ อัปเดตสำเร็จ');renderUsr();}else toast(r.msg,'er');
}
async function delUsr(id){
  if(!confirm('ลบผู้ใช้นี้?'))return;
  const r=await DEL('del_user',id);
  if(r.ok){toast('🗑️ ลบแล้ว');renderUsr();}else toast(r.msg,'er');
}

// ── APPROVE ORDERS ───────────────────────────────────────────
async function renderAppr(){
  const el=$('s-appr');
  el.innerHTML=`<div class="ph"><div class="ph-t">อนุมัติการสั่งซื้อ</div></div><div id="al">${load()}</div>`;
  const r=await GET('orders');const orders=r.data||[];
  const pend=orders.filter(o=>o.status==='pending');
  const done=orders.filter(o=>o.status!=='pending').slice(0,5);
  const sc={approved:'bg',rejected:'br'};const sl={approved:'อนุมัติแล้ว',rejected:'ปฏิเสธ'};
  $('al').innerHTML=
    (!pend.length?`<div class="card">${empty('✅','ไม่มีคำสั่งซื้อรออนุมัติ')}</div>`:'')
    +pend.map(o=>`
      <div class="card" style="margin-bottom:12px">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
          <div><div style="font-weight:700">คำสั่งซื้อ #${o.order_id}</div>
            <div style="font-size:12px;color:var(--mu)">โดย ${o.req_name} • ${(o.requested_at||'').slice(0,16)}</div></div>
          <span class="bdg by">รออนุมัติ</span>
        </div>
        ${(o.items||[]).map(it=>`
          <div class="oir">
            <div style="display:flex;align-items:center;gap:9px">
              <span style="font-size:20px">${it.ing_img||'📦'}</span>
              <div><div style="font-weight:600;font-size:13px">${it.ing_name}</div>
                <div style="font-size:11px;color:var(--mu)">สต็อกปัจจุบัน: ${parseFloat(it.stock_qty||0)} ${it.unit_name}</div></div>
            </div>
            <div style="text-align:right">
              <div style="font-weight:700;color:var(--ac)">${parseFloat(it.qty)} ${it.unit_name}</div>
              <div style="font-size:11px;color:var(--mu)">รวม ฿${(parseFloat(it.qty)*parseFloat(it.price_per_unit)).toLocaleString(undefined,{maximumFractionDigits:2})}</div>
            </div>
          </div>`).join('')}
        ${o.note?`<div style="font-size:12px;color:var(--mu);margin-top:8px;padding:7px 10px;background:#f9f7f4;border-radius:6px">💬 ${o.note}</div>`:''}
        <div style="display:flex;gap:8px;margin-top:12px">
          <button class="btn btn-g" onclick="apprOrd(${o.order_id})">✅ อนุมัติ</button>
          <button class="btn btn-r" onclick="rejOrd(${o.order_id})">❌ ปฏิเสธ</button>
        </div>
      </div>`).join('')
    +(done.length?'<div style="font-weight:600;font-size:13px;margin:8px 0;color:var(--mu)">ประวัติล่าสุด</div>'
      +done.map(o=>`<div class="card" style="display:flex;justify-content:space-between;padding:11px 16px;margin-bottom:6px">
        <div><div style="font-weight:600;font-size:13px">คำสั่งซื้อ #${o.order_id} (${(o.items||[]).length} รายการ)</div>
          <div style="font-size:12px;color:var(--mu)">โดย ${o.req_name}</div></div>
        <span class="bdg ${sc[o.status]}">${sl[o.status]}</span>
      </div>`).join('') : '');
}
async function apprOrd(id){
  const r=await PUT('approve_order',null,id);
  if(r.ok){toast('✅ อนุมัติแล้ว');renderAppr();}
  else toast(r.msg,'er');
}
async function rejOrd(id){
  const r=await PUT('reject_order',null,id);
  if(r.ok){toast('❌ ปฏิเสธแล้ว','er');renderAppr();}else toast(r.msg,'er');
}
</script>
</body>
</html>