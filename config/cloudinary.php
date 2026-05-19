<?php
// Cloudinary upload helper — replaces local filesystem storage so photos survive redeployments

define('CLOUDINARY_CLOUD', getenv('CLOUDINARY_CLOUD_NAME') ?: 'do5b7cqgx');
define('CLOUDINARY_KEY',   getenv('CLOUDINARY_API_KEY')    ?: '983481928839742');
define('CLOUDINARY_SEC',   getenv('CLOUDINARY_API_SECRET') ?: 'OweGsA3-1a5mx4BNxQBQknlk12s');

function cloudinary_upload($tmp_path, $public_id = null) {
    $timestamp = time();
    $params = ['folder' => 'topiclaunch/creators', 'timestamp' => $timestamp];
    if ($public_id) $params['public_id'] = $public_id;

    ksort($params);
    $sig_str = '';
    foreach ($params as $k => $v) {
        if ($sig_str) $sig_str .= '&';
        $sig_str .= $k . '=' . $v;
    }
    $sig_str .= CLOUDINARY_SEC;
    $signature = sha1($sig_str);

    $post = $params;
    $post['api_key']   = CLOUDINARY_KEY;
    $post['signature'] = $signature;
    $post['file']      = new CURLFile($tmp_path);

    $ch = curl_init('https://api.cloudinary.com/v1_1/' . CLOUDINARY_CLOUD . '/image/upload');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $post,
    ]);
    $response = json_decode(curl_exec($ch), true);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (empty($response['secure_url'])) {
        throw new Exception('Cloudinary upload failed (' . $http_code . '): ' . json_encode($response));
    }
    return $response['secure_url'];
}

// Returns the display URL for a profile_image value (handles Cloudinary URLs, old filenames, and base64)
function creator_photo_url($profile_image, $profile_image_data = null) {
    if (!empty($profile_image_data)) return $profile_image_data;
    if (empty($profile_image))       return '';
    if (strpos($profile_image, 'http') === 0) return $profile_image;
    return '/uploads/creators/' . $profile_image;
}
