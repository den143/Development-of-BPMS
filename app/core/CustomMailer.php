<?php
// bpms/app/core/CustomMailer.php

function get_smtp_response($socket) {
    $data = "";
    while($str = fgets($socket, 515)) {
        $data .= $str;
        if(substr($str, 3, 1) == " ") break; 
    }
    return $data;
}

function sendCustomEmail($to, $subject, $body) {
    // 1. CONFIGURATION: Use Port 587 for STARTTLS
    $host = 'smtp.gmail.com'; // No 'ssl://' prefix here!
    $port = 587;
    $username = 'secondemailkoini@gmail.com'; 
    $password = 'vzfi rfhy udjx upfg';// Your App Password
    $fromName = 'BPMS Website';

    echo "<h3>--- SMTP DEBUG LOG (Port 587) ---</h3>";

    // 2. CONNECT (Plain TCP first)
    echo "Connecting to $host:$port...<br>";
    $socket = fsockopen($host, $port, $errno, $errstr, 30);
    if (!$socket) {
        echo "<b style='color:red'>Connection Failed: $errstr ($errno)</b><br>";
        return false;
    }
    echo "Connected! Server said: " . get_smtp_response($socket) . "<br>";

    // 3. HANDSHAKE & UPGRADE SECURITY
    echo "Sending EHLO...<br>";
    fwrite($socket, "EHLO " . $host . "\r\n");
    echo "Server: " . get_smtp_response($socket) . "<br>";

    echo "Sending STARTTLS...<br>";
    fwrite($socket, "STARTTLS\r\n");
    $tlsResponse = get_smtp_response($socket);
    echo "Server: " . $tlsResponse . "<br>";

    if (strpos($tlsResponse, '220') === false) {
        echo "<b style='color:red'>STOPPING: Server did not accept STARTTLS.</b><br>";
        fclose($socket);
        return false;
    }

    // --- ENABLE CRYPTO (The Magic Part) ---
    echo "Enabling Encryption...<br>";
    if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
        echo "<b style='color:red'>STOPPING: Crypto Enable Failed!</b><br>";
        fclose($socket);
        return false;
    }
    echo "Encryption Enabled! Resending EHLO...<br>";

    // Send EHLO again inside the encrypted channel
    fwrite($socket, "EHLO " . $host . "\r\n");
    get_smtp_response($socket); // Ignore response

    // 4. AUTHENTICATE
    echo "Sending AUTH LOGIN...<br>";
    fwrite($socket, "AUTH LOGIN\r\n");
    get_smtp_response($socket);

    echo "Sending Username...<br>";
    fwrite($socket, base64_encode($username) . "\r\n");
    get_smtp_response($socket);

    echo "Sending Password...<br>";
    fwrite($socket, base64_encode($password) . "\r\n");
    $authResult = get_smtp_response($socket);
    echo "Server: " . $authResult . "<br>";

    if (strpos($authResult, '235') === false) {
        echo "<b style='color:red'>STOPPING: Authentication Failed!</b><br>";
        fclose($socket);
        return false;
    }

    // 5. SEND EMAIL
    fwrite($socket, "MAIL FROM: <$username>\r\n");
    get_smtp_response($socket);

    fwrite($socket, "RCPT TO: <$to>\r\n");
    get_smtp_response($socket);

    fwrite($socket, "DATA\r\n");
    get_smtp_response($socket);

    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-type: text/html; charset=utf-8\r\n";
    $headers .= "From: $fromName <$username>\r\n";
    $headers .= "To: $to\r\n";
    $headers .= "Subject: $subject\r\n";
    
    fwrite($socket, "$headers\r\n$body\r\n.\r\n");
    $sendResult = get_smtp_response($socket);
    echo "SEND response: " . $sendResult . "<br>";

    fwrite($socket, "QUIT\r\n");
    fclose($socket);

    echo "<h3>--- END DEBUG LOG ---</h3>";
    return (strpos($sendResult, '250') !== false);
}
?>