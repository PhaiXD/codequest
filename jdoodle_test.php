<?php
$url = 'https://api.jdoodle.com/v1/execute';
$data = json_encode([
    'clientId' => 'a1d1d3b11b1460847565f4cac2792ed0',
    'clientSecret' => '1412778c04a44e1e6f54827351c3088e873464057f07a51a0346dd21f6307116',
    'script' => 'print("hello from server")',
    'language' => 'python3',
    'versionIndex' => '4',
    'stdin' => ''
]);
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'User-Agent: Mozilla/5.0']);
curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo "HTTP: $httpCode\n";
echo "Response: $response\n";
