<?php
include "init.php";
include "stuff.php";
header("Content-Type: text/plain; charset=utf8");
if (strpos($_SERVER["HTTP_USER_AGENT"],"python-requests/")===0) die('{"\u0073\u0075\u0063\u0063\u0065\u0073\u0073":false,"error":"Bitte aktualisiere deinen Client!"}');

$p = $_SERVER["PATH_INFO"];

switch($p) {
case "/barcodedrop/":
  include("barcodedrop.php");
  break;

case "/searchuser/": session_start();
  if (isset($_SESSION["user"])) $user = $_SESSION["user"]; else $user = basiclogin();
  
  $account = sql("select id from users where email=?", [$_GET["email"]], 1);
  if (!$account)die(json_encode(["success"=>false,"error"=>"ENOENT"]));
  echo json_encode(["success"=>true, "account_id" => accountNumber($account)]);
  break;

case "/last_contacts/": session_start();
  if (isset($_SESSION["user"])) $user = $_SESSION["user"]; else $user = basiclogin();
  header("Content-Type: application/json; charset=utf8");
  $accounts = sql("select users.id,users.fullname from ledger l1 inner join ledger l2 on l1.transfer_uid=l2.id inner join users on l2.user_id=users.id 
    WHERE l1.user_id=? GROUP BY users.id order by max(l1.timestamp) DESC", [$user["id"]]);
  foreach($accounts as &$d) $d["account_number"]= accountNumber($d);
  echo json_encode(["success"=>true, "accounts" => $accounts]);
  break;

case "/istgeradejemandda/":
  $last_actions = sql("SELECT MAX(current_state_timeout) t FROM scanners ORDER BY current_state_timeout DESC", []);
  echo $last_actions[0]["t"];
  break;

case "/lastscanned/":
  $code = sql("SELECT current_display FROM scanners ORDER BY current_display_timeout DESC LIMIT 1", [] , 1)["current_display"];
  $code = explode("\n", $code);
  if (strstr($code[1], "INVALID")) echo $code[2];
  break;

case "/textdisplay/":
  $scanner = sql("SELECT current_display FROM scanners WHERE id = ? and current_display_timeout > NOW() limit 1", [ $_GET["scanner"] ], 1);
  if ($scanner)
    echo $scanner["current_display"];
  else {
    $secs = date("s") % 30;
    echo "XX 10 XXXXXXXXXX\n".date("D, d.m H:i")."\n";
    if ($secs < 10)  echo (filemtime("ad")>time()-1200) ? file_get_contents("ad") : "* Hier koennte *\n* Ihre Werbung *\n*    stehen    *";
    else if ($secs < 20)  echo "    \n   WILLKOMMEN\n";
    else if ($secs < 30)  echo "Bitte Karte oder\nFeedbackbogen\nscannen";
  }
  break;

case "/ad/":
  if ($_SERVER["REQUEST_METHOD"] == "PUT") {
    sleep(1);
    $li = explode("\n", file_get_contents("php://input"));
    $out = "";
    for($i=0;$i<3;$i++){
      if(1 !== preg_match('/^[a-zA-Z0-9!"§$%&\/(),.-;:_ =?+#*\']{0,14}$/', $li[$i])) {
        header("HTTP/1.1 400 Bad Request"); echo "Invalid line $i\n"; return;
      }
      $out .= "*".str_pad($li[$i], 14)."*\n";
    }
    if ($out == file_get_contents("ad")) {
      header("HTTP/1.1 304 Not Modified"); exit;
    }
    file_put_contents("ad", $out);
    header("HTTP/1.1 201 Created");
  } else if ($_SERVER["REQUEST_METHOD"] == "GET") {
    echo file_get_contents("ad");
  } else {
    header("HTTP/1.1 405 Method Not Allowed");
  }
  break;

case "/display/":
  header("Content-Type: application/json; charset=utf8");
  $scanner = sql("SELECT *, TIMESTAMPDIFF(SECOND,NOW(),current_state_timeout) timeout FROM scanners
    WHERE id = ? AND greatest(current_state_timeout, current_display_timeout, last_changed_at) > ? LIMIT 1",
    [ $_GET["scanner"], $_GET["t1"] ], 1);
  if (!$scanner || md5($scanner["id"].$scanner["token"]) != $_GET["token"]) die("{}");

  if (strtotime($scanner["current_display_timeout"]) > time()) {
    $parts = explode("\n", $scanner["current_display"]);
    $bg = $parts[0] == "OK" ? "#aaffaa" : ($parts[0] == "SCAN" ? "#aaaaff" : "#ffaaaa");
    $q.= "<pre style='padding:10px; background: $bg;'>".$scanner["current_display"]."</pre>";
  }
$q.= "<div style='float:right;font-size:9pt;color:#888'>".$_SERVER["REMOTE_ADDR"]."</div>";
  if (strtotime($scanner["current_state_timeout"]) <= time()) {
    $q.= "<h2>Herzlich willkommen!</h2>";
  } else {
    $user = sql("SELECT * FROM users WHERE id = ?", [ $scanner["current_user_id"] ], 1);
    $q.= "<h2>Hallo ".$user["fullname"]."</h2>";
    $ledger = sql("SELECT * FROM ledger l LEFT OUTER JOIN products p ON l.product_id=p.id
      WHERE user_id = ? AND storno IS NULL ORDER BY timestamp DESC LIMIT 3",
      [ $scanner["current_user_id"] ]);
    $q.= get_view("ledger", [ "ledger" => $ledger, "mini" => true ]);
    $schulden = get_user_debt($scanner["current_user_id"]);
    if ($schulden < 0)
      $q.= sprintf("<h2>Guthaben: %04.2f</h2>", -($schulden/100));
    else
      $q.= sprintf("<h2>Schulden: %04.2f</h2>", $schulden/100);
    $q.= "<p class=text-muted>State: $scanner[current_state]  |  Timeout: $scanner[timeout] sec</p>";

  }
  echo json_encode(["html" => $q, "t1" => time() ]);
  break;

case "/me/display/":
  $user = basiclogin();
  header("Content-Type: text/html; charset=utf-8");
  $q.= "<html><head><meta charset='utf-8'></head><body><noscript><div style='background:red;padding:100px 10px'>FEHLER: Bitte Javascript aktivieren!</div><br><br><br><br></noscript>";
  $q.= "<table bgcolor='#eee' width=100% style=margin-bottom:1em>\n\n<tr><td>".htmlentities($user["fullname"],0,"UTF-8")."</td>";
  $schulden = get_user_debt($user["id"]);
  if ($schulden < 0)
    $q.= sprintf("<td bgcolor=#cfc><b>Guthaben: %04.2f", -($schulden/100));
  else
    $q.= sprintf("<td bgcolor=#fcc><b>Schulden: %04.2f", $schulden/100);
  $q .= "</b></td></tr></table>";
  $ledger = sql("SELECT * FROM ledger l LEFT OUTER JOIN products p ON l.product_id=p.id
    WHERE user_id = ? AND storno IS NULL ORDER BY timestamp DESC LIMIT 10",
    [ $user["id"] ]);
  $q.="<table width=100%>";
  foreach($ledger as $d) {
    $cls = ($d["storno"]) ? "storno" : "";
    $q.=sprintf("<tr class='%s' style=color:#999;font-size:70%%><td>%s</td><td align=right>%s</td></tr><td colspan=2 style=padding-bottom:1em><span style=float:right;color:%s>%04.2f</span>%s</td></tr>", 
        $cls, $d["timestamp"], $d["code"], $d["charge"]>0?"#b33":"#3b3", -($d["charge"]/100), ent($d["comment"]? $d["comment"] :$d["name"]));
  }
  $q.="</table></body></html>";
  echo $q;
  break;

case "/me/ledger/":
  $user = basiclogin();
  header("Content-Type: application/json; charset=utf8");
  $ledger = sql("SELECT * FROM ledger l LEFT OUTER JOIN products p ON l.product_id=p.id
    WHERE user_id = ? AND storno IS NULL ORDER BY timestamp DESC ",
    [ $user["id"] ]);

  $schulden = get_user_debt($user["id"])/100;
  echo json_encode(["success" => true, "ledger" => $ledger, "debt" => $schulden]);
  break;

case "/productlist/":
  $user = basiclogin();
  header("Content-Type: application/json; charset=utf-8");
  $products = sql("SELECT * FROM products WHERE disabled_at IS NULL", []);
  echo json_encode($products,JSON_PRETTY_PRINT);
  break;

case "/me/buy/":
  $user = basiclogin();
  header("Content-Type: application/json; charset=utf-8");
  error_log(print_r($_POST,true));
  if (!isset($_POST["barcode"]) || strlen($_POST["barcode"]) < 4) {
    echo json_encode(["error" => "missing_parameter"]);
    return;
  }
  $product_result = sql("SELECT * FROM products WHERE code = ? AND disabled_at IS NULL", [ $_POST["barcode"] ]);
  if (count($product_result) == 1) {
    $res = buy_product($user["id"], $product_result[0]);
    if ($res === true) {
      echo json_encode(["success" => true]);
    } else {
      echo json_encode(["success" => false, "error" => $res ]);
    }
  } else {
    echo json_encode(["success" => false, "error" => "unknown_product"]);
  }
  break;

case "/me/deposit/":
  $user = basiclogin();
  header("Content-Type: application/json; charset=utf-8");
  if (!isset($_POST["amount"]) || strlen($_POST["amount"]) < 1) {
    echo json_encode(["success" => false, "error" => "missing_parameter"]);
    return;
  }
  $product = sql("SELECT * FROM products WHERE id = 1", [], 1);
  $product["price"] = - intval($_POST["amount"] * 100);
  if ($product["price"] <= -5000 || $product["price"] >= 0) {
    echo json_encode(["success" => false, "error" => "invalid_amount"]);
    return;
  }
  $res = buy_product($user["id"], $product);
  if ($res === true) echo json_encode(["success" => true]);
  else echo json_encode(["success" => false, "error" => $res ]);
  break;

case "/me/wiretransfer/":
  $user = basiclogin();
  header("Content-Type: application/json; charset=utf-8");

  $product = sql("SELECT * FROM products WHERE id = ?", [ PRODID_WIRETRANSFER ], 1);
  $product["price"] = floatval(str_replace(",",".",$_POST["charge"])) * 100;
  if ($product["price"] <= 0) {
    echo json_encode(["success" => false, "error" => "invalid_charge"]);
    return;
  }

  $to_uid = checkAccountNumber($_POST["transfer_to"]);
  if (!$to_uid) {
    $to_uid = sql("SELECT id FROM users WHERE email = ? OR email = ?", [$_POST["transfer_to"], $_POST["transfer_to"]."@d120.de"], 1);
    if (!$to_uid) {
      echo json_encode(["success" => false, "error" => "invalid_account_number"]);
      return;
    }
    $to_uid = $to_uid["id"];
  }

  $touser = sql("SELECT * FROM users WHERE id = ?", [$to_uid], 1);
  if (!$touser) {
    echo json_encode(["success" => false, "error" => "user_not_found"]);
    return;
  }

  $id1 = $id2 = 0;
  $verwendungszweck = "Überweisung von $user[email] an $touser[email] : $_POST[verwendungszweck]";
  $ok = buy_product($user["id"], $product, $verwendungszweck, null, $id1);
  if ($ok !== true) {
    echo json_encode(["success" => false, "error" => $ok]);
    return;
  }
  $product["price"] = -$product["price"];
  $ok = buy_product($touser["id"], $product, $verwendungszweck, $id1, $id2);
  sql("UPDATE ledger SET transfer_uid = ? WHERE id = ? LIMIT 1 ", [ $id2, $id1 ], true);
if ($ok !== true) {
    echo json_encode(["success" => false, "error" => $ok]);
  } else {
    echo json_encode(["success" => true]);
  }
  break;

case "/me/register_notifications/":
  $user = basiclogin();
  header("Content-Type: application/json; charset=utf-8");
  $token = urldecode($_GET['token']);
  if ($token == 'null') {
    if ($user['gcm_token'])
        send_gcm_message($user['gcm_token'], ['title'=>'KDV notifications unregistered', 'message' => 'Die Registrierung wurde aufgehoben.']);
    sql("UPDATE users SET gcm_token = NULL WHERE id = ?", [$user['id']], true);

    echo json_encode(["success"=>true]);
  } elseif ($token == $user['gcm_token']) {
    echo json_encode(["success"=>true,"changed"=>false]);

  } else {

    $result = json_decode(send_gcm_message($_GET['token'], ['title'=>'Hello, world.', 'message'=>"Registriert für KDV-Notifications!\nnewToken=$token\noldToken=$user[gcm_token]"]), true);
    if ($result['success']>0) {
      if ($user['gcm_token'])
        send_gcm_message($user['gcm_token'], ['title'=>'KDV notifications unregistered', 'message' => 'Ein anderes Gerät wurde stattdessen registriert.']);

      sql("UPDATE users SET gcm_token = ? WHERE id = ?", [urldecode($_GET['token']), $user['id']], true);
      echo json_encode(["success"=>true,"changed"=>true,"old"=>$user['gcm_token'], "new"=>$token ]);
    } else {
      echo json_encode(["success"=>false,"error"=>$response["results"][0]["error"]]);
    }
  }
  break;

default:
  echo "FAIL\n404\n";
  break;
}


