<?php
/**
 * Profile picture upload validation.
 * Never trusts the client-supplied filename or MIME type -- verifies
 * the file is a real image by reading its actual bytes.
 */

class UploadException extends Exception {}

const PROFILE_PICTURE_MAX_BYTES = 2 * 1024 * 1024; // 2MB

const PROFILE_PICTURE_ALLOWED_TYPES = [
    IMAGETYPE_JPEG => 'jpg',
    IMAGETYPE_PNG  => 'png',
    IMAGETYPE_WEBP => 'webp',
];

/**
 * Validates an uploaded file array entry ($_FILES['field']) as a
 * profile picture. Returns the safe file extension to use on success.
 * Throws UploadException with a user-safe message on failure.
 */
function validate_profile_picture_upload(array $file): string
{
    if (!isset($file['error']) || is_array($file['error'])) {
        throw new UploadException('Invalid upload.');
    }

    switch ($file['error']) {
        case UPLOAD_ERR_OK:
            break;
        case UPLOAD_ERR_NO_FILE:
            throw new UploadException('No file was selected.');
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            throw new UploadException('That image is too large.');
        default:
            throw new UploadException('The upload failed. Please try again.');
    }

    if ($file['size'] > PROFILE_PICTURE_MAX_BYTES) {
        throw new UploadException('Images must be 2MB or smaller.');
    }

    // The check that actually matters: read the file's real content,
    // not its name or the browser-supplied Content-Type, both of
    // which are fully attacker-controlled.
    $imageInfo = @getimagesize($file['tmp_name']);
    if ($imageInfo === false) {
        throw new UploadException('That file is not a valid image.');
    }

    $detectedType = $imageInfo[2];
    if (!array_key_exists($detectedType, PROFILE_PICTURE_ALLOWED_TYPES)) {
        throw new UploadException('Images must be JPG, PNG, or WEBP.');
    }

    if (!is_uploaded_file($file['tmp_name'])) {
        throw new UploadException('Invalid upload.');
    }

    return PROFILE_PICTURE_ALLOWED_TYPES[$detectedType];
}

function validate_display_name(string $name): ?string
{
    $name = trim($name);
    if ($name === '') {
        return 'Name is required.';
    }
    if (mb_strlen($name) > 100) {
        return 'Name must be 100 characters or fewer.';
    }
    return null;
}
