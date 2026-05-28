<?php
session_start();

/*
|--------------------------------------------------------------------------
| ENVIRONMENT
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION['servers'])) {

    $_SESSION['servers'] = [
        "TUDU PROD" => "https://api-bvtudu.tudu.com.vn"
    ];
}

/*
|--------------------------------------------------------------------------
| LƯU SERVER
|--------------------------------------------------------------------------
*/

if (isset($_POST['save_server'])) {

    $serverName = trim($_POST['server_name']);
    $serverUrl  = trim($_POST['server_url']);

    if ($serverName != "" && $serverUrl != "") {

        $_SESSION['servers'][$serverName] = $serverUrl;
    }
}

/*
|--------------------------------------------------------------------------
| BIẾN
|--------------------------------------------------------------------------
*/

$responseData = "";
$token        = "";
$debugToken   = "";
$hl7Message   = "";
$hl7Error     = "";

/*
|--------------------------------------------------------------------------
| HÀM LOGIN LẤY TOKEN
|--------------------------------------------------------------------------
*/

function getAccessToken($baseUrl, $taiKhoan, $matKhau)
{
    $loginUrl = $baseUrl . "/api/his/v1/auth/login";

    $data = [
        "taiKhoan" => $taiKhoan,
        "matKhau"  => $matKhau
    ];

    $ch = curl_init($loginUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

    $response = curl_exec($ch);
    curl_close($ch);

    $decoded = json_decode($response, true);

    if (isset($decoded['access_token']))           return $decoded['access_token'];
    if (isset($decoded['data']['access_token']))   return $decoded['data']['access_token'];
    if (isset($decoded['token']))                  return $decoded['token'];

    return "DEBUG_LOGIN_RESPONSE: " . $response;
}

/*
|--------------------------------------------------------------------------
| HÀM TÌM GIÁ TRỊ THEO KEY TRONG JSON LỒNG NHAU
|--------------------------------------------------------------------------
*/

function findValueByKey($data, $key)
{
    if (!is_array($data)) return null;

    if (array_key_exists($key, $data) && !is_array($data[$key])) {
        return $data[$key];
    }

    foreach ($data as $v) {
        $result = findValueByKey($v, $key);
        if ($result !== null) return $result;
    }

    return null;
}

/*
|--------------------------------------------------------------------------
| HÀM TÌM OBJECT DỊCH VỤ THEO maDichVu
|--------------------------------------------------------------------------
*/

function findServiceByMaDichVu($data, $maDichVu)
{
    if (!is_array($data)) return null;

    if (isset($data['maDichVu']) && (string)$data['maDichVu'] === (string)$maDichVu) {
        return $data;
    }

    foreach ($data as $v) {
        $result = findServiceByMaDichVu($v, $maDichVu);
        if ($result !== null) return $result;
    }

    return null;
}

/*
|--------------------------------------------------------------------------
| HÀM SINH BẢN TIN HL7
|--------------------------------------------------------------------------
*/

function buildHL7($soPhieu, $maNb, $tenNb, $maHoSo, $id, $maDichVu, $tenDichVu)
{
    $msh = "MSH|^~\\\&|LABCONN|LABCONN|HL7_HIS|HIS|||OML^O21^OML_O21|{$soPhieu}|P|2.5|||AL|ER|VNM|UNICODE UTF-8";
    $pid = "PID|1|{$maNb}|||^{$tenNb}";
    $pv1 = "PV1|1||||||||||||||||||{$maHoSo}";
    $orc = "ORC|NW|{$soPhieu}";
    $obr = "OBR||{$id}||{$maDichVu}^{$tenDichVu}";

    // Nối tất cả bằng \r\n literal, cuối cũng có \r\n
    return $msh . "\\r\\n" . $pid . "\\r\\n" . $pv1 . "\\r\\n" . $orc . "\\r\\n" . $obr . "\\r\\n";
}

/*
|--------------------------------------------------------------------------
| LẤY TOKEN
|--------------------------------------------------------------------------
*/

if (isset($_POST['refresh_token'])) {

    $baseUrl  = $_POST['environment'];
    $taiKhoan = $_POST['taiKhoan'];
    $matKhau  = $_POST['matKhau'];

    $token = getAccessToken($baseUrl, $taiKhoan, $matKhau);

    $_SESSION['token'] = $token;

    $debugToken = $token;
}

/*
|--------------------------------------------------------------------------
| GET API
|--------------------------------------------------------------------------
*/

if (isset($_POST['get_data'])) {

    $baseUrl  = $_POST['environment'];
    $taiKhoan = $_POST['taiKhoan'];
    $matKhau  = $_POST['matKhau'];

    $nbDotDieuTriId = trim($_POST['nbDotDieuTriId']);

    $token = getAccessToken($baseUrl, $taiKhoan, $matKhau);

    $_SESSION['token'] = $token;

    $debugToken = $token;

    if (strpos($token, 'DEBUG_LOGIN_RESPONSE:') === 0) {

        $responseData = "=== LỖI LẤY TOKEN ===\n\n" . $token;

    } else {

        $apiUrl =
            $baseUrl .
            "/api/his/v1/nb-dich-vu/tong-hop?page=0&size=10&active=true&nbDotDieuTriId=" .
            urlencode($nbDotDieuTriId);

        $ch = curl_init($apiUrl);

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer " . $token,
            "Content-Type: application/json"
        ]);

        $response = curl_exec($ch);

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {

            $responseData = "Lỗi cURL: " . curl_error($ch);

        } else {

            $decoded = json_decode($response, true);

            if ($decoded) {

                // Lưu vào session để dùng cho sinh bản tin
                $_SESSION['last_response'] = $decoded;

                $responseData = json_encode(
                    $decoded,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
                );

            } else {

                $responseData = $response;
            }

            $responseData = "HTTP CODE: " . $httpCode . "\n\n" . $responseData;
        }

        curl_close($ch);
    }
}

/*
|--------------------------------------------------------------------------
| SINH BẢN TIN HL7
|--------------------------------------------------------------------------
*/

if (isset($_POST['gen_hl7'])) {

    $maDichVuSearch = trim($_POST['maDichVuSearch']);

    if ($maDichVuSearch === "") {

        $hl7Error = "Vui lòng nhập Mã dịch vụ.";

    } elseif (!isset($_SESSION['last_response'])) {

        $hl7Error = "Chưa có dữ liệu. Vui lòng GET dữ liệu trước.";

    } else {

        $jsonData = $_SESSION['last_response'];

        // Tìm object dịch vụ theo maDichVu
        $serviceObj = findServiceByMaDichVu($jsonData, $maDichVuSearch);

        if (!$serviceObj) {

            $hl7Error = "Không tìm thấy dịch vụ có maDichVu = \"" . htmlspecialchars($maDichVuSearch) . "\" trong dữ liệu.";

        } else {

            // Lấy các field từ object dịch vụ trước
            $id         = $serviceObj['id']         ?? findValueByKey($serviceObj, 'id');
            $maDichVu   = $serviceObj['maDichVu']   ?? findValueByKey($serviceObj, 'maDichVu');
            $tenDichVu  = $serviceObj['tenDichVu']  ?? findValueByKey($serviceObj, 'tenDichVu');

            // Các field bệnh nhân tìm trong toàn bộ JSON
            $soPhieu = findValueByKey($jsonData, 'soPhieu');
            $maNb       = findValueByKey($jsonData, 'maNb');
            $tenNb      = findValueByKey($jsonData, 'tenNb');
            $maHoSo     = findValueByKey($jsonData, 'maHoSo');

            // Kiểm tra thiếu field
            $missing = [];
            if (!$soPhieu) $missing[] = 'soPhieu';
            if (!$maNb)       $missing[] = 'maNb';
            if (!$tenNb)      $missing[] = 'tenNb';
            if (!$maHoSo)     $missing[] = 'maHoSo';
            if (!$id)         $missing[] = 'id';
            if (!$maDichVu)   $missing[] = 'maDichVu';
            if (!$tenDichVu)  $missing[] = 'tenDichVu';

            if (!empty($missing)) {

                $hl7Error =
                    "Tìm thấy dịch vụ nhưng thiếu các field: <b>" .
                    implode(', ', $missing) .
                    "</b>.<br>Object dịch vụ tìm được:<br><pre>" .
                    htmlspecialchars(json_encode($serviceObj, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) .
                    "</pre>";

            } else {

                $hl7Message = buildHL7(
                    $soPhieu,
                    $maNb,
                    $tenNb,
                    $maHoSo,
                    $id,
                    $maDichVu,
                    $tenDichVu
                );
            }
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>

    <meta charset="UTF-8">
    <title>Mini API Tool</title>

    <style>

        * { box-sizing: border-box; }

        body {
            font-family: Arial, sans-serif;
            margin: 30px;
            background: #f5f5f5;
            color: #333;
        }

        h2 {
            margin-top: 0;
            color: #1565c0;
            font-size: 16px;
        }

        input, select {
            width: 500px;
            padding: 9px 12px;
            margin-bottom: 10px;
            border: 1px solid #ccc;
            border-radius: 4px;
            font-size: 14px;
        }

        button {
            padding: 9px 18px;
            cursor: pointer;
            margin-right: 8px;
            border: none;
            border-radius: 4px;
            background: #1976d2;
            color: #fff;
            font-size: 14px;
            transition: background 0.2s;
        }

        button:hover { background: #1565c0; }

        button.green { background: #388e3c; }
        button.green:hover { background: #2e7d32; }

        textarea {
            width: 100%;
            height: 400px;
            font-family: Consolas, monospace;
            font-size: 13px;
            border: 1px solid #ccc;
            border-radius: 4px;
            padding: 10px;
            background: #1e1e1e;
            color: #d4d4d4;
            resize: vertical;
        }

        textarea.hl7 {
            height: 200px;
            background: #0d1117;
            color: #58d68d;
            letter-spacing: 0.3px;
        }

        .box {
            border: 1px solid #ddd;
            padding: 20px;
            margin-bottom: 20px;
            border-radius: 6px;
            background: #fff;
        }

        .token-box {
            background: #e8f5e9;
            border: 1px solid #a5d6a7;
            padding: 12px 16px;
            border-radius: 6px;
            margin-bottom: 20px;
            word-break: break-all;
            font-family: Consolas, monospace;
            font-size: 12px;
            color: #2e7d32;
        }

        .token-box.error {
            background: #ffebee;
            border-color: #ef9a9a;
            color: #c62828;
        }

        .token-label {
            font-weight: bold;
            font-size: 13px;
            margin-bottom: 6px;
            display: block;
        }

        .alert-error {
            background: #fff3e0;
            border: 1px solid #ffb74d;
            color: #e65100;
            padding: 12px 16px;
            border-radius: 6px;
            margin-bottom: 16px;
            font-size: 14px;
        }

        .alert-success {
            background: #e8f5e9;
            border: 1px solid #81c784;
            color: #1b5e20;
            padding: 10px 16px;
            border-radius: 6px;
            margin-bottom: 10px;
            font-size: 13px;
        }

        label {
            font-size: 13px;
            color: #555;
            display: block;
            margin-bottom: 3px;
        }

        .field-row {
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
            align-items: flex-start;
            margin-bottom: 6px;
        }

        .field-row .field {
            display: flex;
            flex-direction: column;
        }

        .mapping-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
            margin-bottom: 16px;
        }

        .mapping-table th {
            background: #e3f2fd;
            padding: 8px 12px;
            text-align: left;
            border: 1px solid #bbdefb;
            color: #1565c0;
        }

        .mapping-table td {
            padding: 7px 12px;
            border: 1px solid #e0e0e0;
        }

        .mapping-table tr:nth-child(even) td {
            background: #fafafa;
        }

        .copy-btn {
            background: #546e7a;
            font-size: 12px;
            padding: 6px 12px;
            margin-top: 6px;
        }

        .copy-btn:hover { background: #37474f; }

        pre {
            background: #f5f5f5;
            border: 1px solid #ddd;
            padding: 10px;
            border-radius: 4px;
            font-size: 12px;
            overflow-x: auto;
            white-space: pre-wrap;
            word-break: break-all;
        }

    </style>

</head>
<body>

<!-- ====================================================== -->
<!-- ENVIRONMENT -->
<!-- ====================================================== -->

<div class="box">

    <h2>⚙️ Environment</h2>

    <form method="POST">

        <label>Tên Environment:</label>
        <input type="text" name="server_name" placeholder="VD: PROD">

        <label>Base URL:</label>
        <input type="text" name="server_url" placeholder="VD: https://api.domain.com">

        <button type="submit" name="save_server">Lưu Environment</button>

    </form>

</div>

<!-- ====================================================== -->
<!-- API GET -->
<!-- ====================================================== -->

<div class="box">

    <h2>🔍 API GET nb-dich-vu/tong-hop</h2>

    <form method="POST">

        <label>Environment:</label>
        <select name="environment">
            <?php foreach ($_SESSION['servers'] as $name => $server) { ?>
                <option value="<?php echo htmlspecialchars($server); ?>">
                    <?php echo htmlspecialchars($name . " => " . $server); ?>
                </option>
            <?php } ?>
        </select>

        <label>Tài khoản:</label>
        <input type="text"
               name="taiKhoan"
               value="<?php echo htmlspecialchars($_POST['taiKhoan'] ?? ''); ?>"
               required>

        <label>Mật khẩu MD5:</label>
        <input type="text"
               name="matKhau"
               value="<?php echo htmlspecialchars($_POST['matKhau'] ?? ''); ?>"
               required>

        <label>nbDotDieuTriId:</label>
        <input type="number"
               name="nbDotDieuTriId"
               value="<?php echo htmlspecialchars($_POST['nbDotDieuTriId'] ?? ''); ?>"
               placeholder="Nhập số">

        <div style="margin-top:6px;">
            <button type="submit" name="refresh_token">🔄 Lấy lại Token</button>
            <button type="submit" name="get_data">📡 GET Dữ Liệu</button>
        </div>

    </form>

</div>

<!-- DEBUG TOKEN -->
<?php if ($debugToken != "") { ?>
    <div class="token-box <?php echo strpos($debugToken, 'DEBUG_LOGIN_RESPONSE:') === 0 ? 'error' : ''; ?>">
        <span class="token-label">
            <?php echo strpos($debugToken, 'DEBUG_LOGIN_RESPONSE:') === 0 ? '❌ Lỗi lấy token:' : '✅ Token đang dùng:'; ?>
        </span>
        <?php echo htmlspecialchars($debugToken); ?>
    </div>
<?php } ?>

<!-- JSON RESPONSE -->
<?php if ($responseData != "") { ?>
    <div class="box">
        <h2>📋 JSON Response</h2>
        <textarea readonly><?php echo htmlspecialchars($responseData); ?></textarea>
    </div>
<?php } ?>

<!-- ====================================================== -->
<!-- SINH BẢN TIN HL7 -->
<!-- ====================================================== -->

<div class="box">

    <h2>📨 Sinh bản tin đổi trạng thái chỉ định (HL7)</h2>

    <!-- Mapping reference -->
    <table class="mapping-table">
        <thead>
            <tr>
                <th>Field JSON</th>
                <th>Vị trí HL7</th>
                <th>Segment</th>
            </tr>
        </thead>
        <tbody>
            <tr><td>soPhieu</td><td>MSH-9</td><td>MSH</td></tr>
            <tr><td>maNb</td><td>PID-2</td><td>PID</td></tr>
            <tr><td>tenNb</td><td>PID-5.2 (component 2)</td><td>PID</td></tr>
            <tr><td>maHoSo</td><td>PV1-19</td><td>PV1</td></tr>
            <tr><td>soPhieu</td><td>ORC-2</td><td>ORC</td></tr>
            <tr><td>id</td><td>OBR-2</td><td>OBR</td></tr>
            <tr><td>maDichVu</td><td>OBR-4.1</td><td>OBR</td></tr>
            <tr><td>tenDichVu</td><td>OBR-4.2</td><td>OBR</td></tr>
        </tbody>
    </table>

    <form method="POST">

        <!-- Giữ lại context đăng nhập -->
        <input type="hidden" name="taiKhoan"
               value="<?php echo htmlspecialchars($_POST['taiKhoan'] ?? ''); ?>">
        <input type="hidden" name="matKhau"
               value="<?php echo htmlspecialchars($_POST['matKhau'] ?? ''); ?>">
        <input type="hidden" name="nbDotDieuTriId"
               value="<?php echo htmlspecialchars($_POST['nbDotDieuTriId'] ?? ''); ?>">

        <label>Mã dịch vụ (maDichVu) cần tìm:</label>
        <input type="text"
               name="maDichVuSearch"
               value="<?php echo htmlspecialchars($_POST['maDichVuSearch'] ?? ''); ?>"
               placeholder="VD: XN08"
               style="width:300px;">

        <div style="margin-top:6px;">
            <button type="submit" name="gen_hl7" class="green">
                ⚡ Sinh bản tin HL7
            </button>
        </div>

    </form>

    <!-- Lỗi -->
    <?php if ($hl7Error != "") { ?>
        <div class="alert-error" style="margin-top:16px;">
            ❌ <?php echo $hl7Error; ?>
        </div>
    <?php } ?>

    <!-- Kết quả HL7 -->
    <?php if ($hl7Message != "") { ?>

        <div class="alert-success" style="margin-top:16px;">
            ✅ Sinh bản tin thành công cho maDichVu = <b><?php echo htmlspecialchars($_POST['maDichVuSearch']); ?></b>
        </div>

        <textarea class="hl7"
                  id="hl7Output"
                  readonly><?php echo htmlspecialchars($hl7Message); ?></textarea>

        <button class="copy-btn"
                onclick="copyHL7()"
                type="button">
            📋 Copy bản tin
        </button>

        <span id="copyNotice"
              style="font-size:13px; color:#388e3c; margin-left:8px; display:none;">
            Đã copy!
        </span>

    <?php } ?>

</div>

<script>
function copyHL7() {
    const ta = document.getElementById('hl7Output');
    ta.select();
    ta.setSelectionRange(0, 99999);
    navigator.clipboard.writeText(ta.value).then(() => {
        const notice = document.getElementById('copyNotice');
        notice.style.display = 'inline';
        setTimeout(() => notice.style.display = 'none', 2000);
    });
}
</script>

</body>
</html>