<?php

namespace SilverStripe\Assets;

use SilverStripe\Core\Convert;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;

class Upload_Validator
{
    use Injectable;
    use Configurable;

    /**
     * Contains a list of the max file sizes shared by
     * all upload fields. This is then duplicated into the
     * "allowedMaxFileSize" instance property on construct.
     *
     * @config
     * @var array
     */
    private static $default_max_file_size = [];

    /**
     * Set to false to assume is_uploaded_file() is true,
     * Set to true to actually call is_uploaded_file()
     * Useful to use when testing uploads
     *
     * @config
     * @var bool
     */
    private static $use_is_uploaded_file = true;

    /**
     * Information about the temporary file produced
     * by the PHP-runtime.
     *
     * @var array
     */
    protected $tmpFile;

    protected $errors = [];

    /**
     * Restrict filesize for either all filetypes
     * or a specific extension, with extension-name
     * as array-key and the size-restriction in bytes as array-value.
     *
     * @var array
     */
    public $allowedMaxFileSize = [];

    /**
     * @var array Collection of extensions.
     * Extension-names are treated case-insensitive.
     *
     * Example:
     * <code>
     *    array("jpg","GIF")
     * </code>
     */
    public $allowedExtensions = [];

    /**
     * Return all errors that occurred while validating
     * the temporary file.
     *
     * @return array
     */
    public function getErrors()
    {
        return $this->errors;
    }

    /**
     * Clear out all errors
     *
     * @return $this
     */
    public function clearErrors()
    {
        $this->errors = [];

        return $this;
    }

    /**
     * Set information about temporary file produced by PHP.
     *
     * @param array $tmpFile
     *
     * @return $this
     */
    public function setTmpFile($tmpFile)
    {
        $this->tmpFile = $tmpFile;

        return $this;
    }


    /**
     * Returns the largest maximum filesize allowed across all extensions
     *
     * @return null|int Filesize in bytes
     */
    public function getLargestAllowedMaxFileSize()
    {
        if (!count($this->allowedMaxFileSize ?? [])) {
            return null;
        }

        return max(array_values($this->allowedMaxFileSize ?? []));
    }

    /**
     * Get maximum file size for all or specified file extension.
     *
     * @param string $ext
     * @return int Filesize in bytes
     */
    public function getAllowedMaxFileSize($ext = null)
    {
        // Check if there is any defined instance max file sizes
        if (empty($this->allowedMaxFileSize)) {
            // Set default max file sizes if there isn't
            $fileSize = Config::inst()->get(__CLASS__, 'default_max_file_size');
            if ($fileSize) {
                $this->setAllowedMaxFileSize($fileSize);
            } else {
                // When no default is present, use maximum set by PHP
                $this->setAllowedMaxFileSize($this->maxUploadSizeFromPHPIni());
            }
        }

        if ($ext !== null) {
            $ext = strtolower($ext ?? '');
            if (isset($this->allowedMaxFileSize[$ext])) {
                return $this->allowedMaxFileSize[$ext];
            }

            $category = File::get_app_category($ext);
            if ($category && isset($this->allowedMaxFileSize['[' . $category . ']'])) {
                return $this->allowedMaxFileSize['[' . $category . ']'];
            }
        }

        return (isset($this->allowedMaxFileSize['*'])) ? $this->allowedMaxFileSize['*'] : false;
    }

    /**
     * Set filesize maximums (in bytes or INI format).
     * Automatically converts extensions to lowercase
     * for easier matching.
     *
     * Example:
     * <code>
     * array('*' => 200, 'jpg' => 1000, '[doc]' => '5m')
     * </code>
     *
     * @param array|int|string $rules
     *
     * @return $this
     */
    public function setAllowedMaxFileSize($rules)
    {
        if (is_array($rules) && count($rules ?? [])) {
            // make sure all extensions are lowercase
            $rules = array_change_key_case($rules ?? [], CASE_LOWER);
            $finalRules = [];

            foreach ($rules as $rule => $value) {
                $finalRules[$rule] = $this->parseAndVerifyAllowedSize($value);
            }

            $this->allowedMaxFileSize = $finalRules;
        } else {
            $this->allowedMaxFileSize['*'] = $this->parseAndVerifyAllowedSize($rules);
        }

        return $this;
    }

    /**
     * Converts values in bytes or INI format to bytes.
     * Will fall back to the default PHP size if null|0|''
     * Verify the parsed size will not exceed PHP's ini settings
     */
    private function parseAndVerifyAllowedSize($value): int
    {
        $maxPHPAllows = $this->maxUploadSizeFromPHPIni();

        if (is_numeric($value)) {
            $tmpSize = (int)$value;
        } elseif (is_string($value)) {
            $tmpSize = Convert::memstring2bytes($value);
        }

        if (empty($tmpSize)) {
            $tmpSize = $maxPHPAllows;
        }

        return min($maxPHPAllows, $tmpSize);
    }

    /**
     * Checks the two ini settings to work out the max upload size php would allow
     */
    private function maxUploadSizeFromPHPIni(): int
    {
        $maxUpload = Convert::memstring2bytes(ini_get('upload_max_filesize'));
        $maxPost = Convert::memstring2bytes(ini_get('post_max_size'));
        return min($maxUpload, $maxPost);
    }

    /**
     * @return array
     */
    public function getAllowedExtensions()
    {
        return $this->allowedExtensions;
    }

    /**
     * Limit allowed file extensions. Empty by default, allowing all extensions.
     * To allow files without an extension, use an empty string.
     * See {@link File::$allowed_extensions} to get a good standard set of
     * extensions that are typically not harmful in a webserver context.
     * See {@link setAllowedMaxFileSize()} to limit file size by extension.
     *
     * @param array $rules List of extensions
     *
     * @return $this
     */
    public function setAllowedExtensions($rules)
    {
        if (!is_array($rules)) {
            return;
        }

        // make sure all rules are lowercase
        foreach ($rules as &$rule) {
            $rule = strtolower($rule ?? '');
        }

        $this->allowedExtensions = $rules;

        return $this;
    }

    /**
     * Determines if the bytesize of an uploaded
     * file is valid - can be defined on an
     * extension-by-extension basis in {@link $allowedMaxFileSize}
     *
     * @return boolean
     */
    public function isValidSize()
    {
        // If file was blocked via PHP for being excessive size, shortcut here
        switch ($this->tmpFile['error']) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return false;
        }
        $maxSize = $this->getAllowedMaxFileSize($this->getFileExtension());
        return (!$this->tmpFile['size'] || !$maxSize || (int)$this->tmpFile['size'] < $maxSize);
    }

    /**
     * Determine if this file is valid but empty
     *
     * @return bool
     */
    public function isFileEmpty()
    {
        // Don't check file size for errors
        if ($this->tmpFile['error'] !== UPLOAD_ERR_OK) {
            return false;
        }
        return empty($this->tmpFile['size']);
    }

    /**
     * Determines if the temporary file has a valid extension
     * An empty string in the validation map indicates files without an extension.
     * @return boolean
     */
    public function isValidExtension()
    {
        return !count($this->allowedExtensions ?? [])
            || in_array($this->getFileExtension(), $this->allowedExtensions ?? [], true);
    }

    /**
     * Return the extension of the uploaded file, in lowercase
     * Returns an empty string for files without an extension
     *
     * @return string
     */
    public function getFileExtension()
    {
        $pathInfo = pathinfo($this->tmpFile['name'] ?? '');
        if (isset($pathInfo['extension'])) {
            return strtolower($pathInfo['extension'] ?? '');
        }

        // Special case for files without extensions
        return '';
    }

    /**
     * Run through the rules for this validator checking against
     * the temporary file set by {@link setTmpFile()} to see if
     * the file is deemed valid or not.
     *
     * @return boolean
     */
    public function validate()
    {
        // we don't validate for empty upload fields yet
        if (empty($this->tmpFile['name'])) {
            return true;
        }

        // Check file upload
        if (!$this->isValidUpload()) {
            $this->errors[] = _t('SilverStripe\\Assets\\File.NOVALIDUPLOAD', 'File is not a valid upload');
            return false;
        }

        if (!$this->isCompleteUpload()) {
            $this->errors[] = _t(
                'SilverStripe\\Assets\\File.PARTIALUPLOAD',
                'File did not finish uploading, please try again'
            );
            return false;
        }

        // Check file isn't empty
        if ($this->isFileEmpty()) {
            $this->errors[] = _t('SilverStripe\\Assets\\File.NOFILESIZE', 'Filesize is zero bytes.');
            return false;
        }

        // filesize validation
        if (!$this->isValidSize()) {
            $arg = File::format_size($this->getAllowedMaxFileSize($this->getFileExtension()));
            $this->errors[] = _t(
                'SilverStripe\\Assets\\File.TOOLARGE',
                'Filesize is too large, maximum {size} allowed',
                'Argument 1: Filesize (e.g. 1MB)',
                ['size' => $arg]
            );
            return false;
        }

        // extension validation
        if (!$this->isValidExtension()) {
            $this->errors[] = _t(
                'SilverStripe\\Assets\\File.INVALIDEXTENSION_SHORT_EXT',
                'Extension \'{extension}\' is not allowed',
                [ 'extension' => $this->getFileExtension() ]
            );
            return false;
        }

        return true;
    }

    /**
     * Check that a valid file was given for upload (ignores file size)
     *
     * @return bool
     */
    public function isValidUpload()
    {
        // Check file upload
        if (in_array($this->tmpFile['error'], [UPLOAD_ERR_NO_FILE, UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE])) {
            return false;
        }

        // Note that some "max file size" errors leave "tmp_name" empty, so don't fail on this.
        if (empty($this->tmpFile['tmp_name'])) {
            return true;
        }

        // Check if file is valid uploaded (with exception for unit testing)
        $useUploadedFile = $this->config()->get('use_is_uploaded_file');
        if ($useUploadedFile && !is_uploaded_file($this->tmpFile['tmp_name'] ?? '')) {
            return false;
        }

        return true;
    }

    /**
     * Check whether the file was fully uploaded
     *
     * @return bool
     */
    public function isCompleteUpload()
    {
        return ($this->tmpFile['error'] !== UPLOAD_ERR_PARTIAL);
    }
}
