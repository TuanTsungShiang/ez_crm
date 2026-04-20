<?php

namespace App\Enums;

class ApiCode
{
    // S — 成功類
    const OK = 'S200';
    const CREATED = 'S201';

    // V — 驗證類
    const MISSING_FIELD = 'V001';
    const INVALID_FORMAT = 'V002';
    const OUT_OF_RANGE = 'V003';
    const INVALID_ENUM = 'V004';
    const INVALID_RELATION = 'V005';
    const DUPLICATE_FIELD = 'V006';

    // A — 認證 / 授權類
    const NO_TOKEN = 'A001';
    const TOKEN_EXPIRED = 'A002';
    const FORBIDDEN = 'A003';
    const ACCOUNT_SUSPENDED = 'A004';
    const EMAIL_NOT_VERIFIED = 'A005';
    const ALREADY_VERIFIED = 'A006';
    const INVALID_CODE = 'A007';
    const THROTTLED = 'A008';
    const INVALID_CREDENTIALS = 'A009';

    // N — 資源不存在類
    const NOT_FOUND = 'N001';
    const ENDPOINT_NOT_FOUND = 'N002';

    // I — 內部錯誤類
    const UNKNOWN_ERROR = 'I000';

    /**
     * 根據 Laravel validation rule 名稱對應 V code
     */
    private static array $ruleMapping = [
        'required'         => self::MISSING_FIELD,
        'required_with'    => self::MISSING_FIELD,
        'required_without' => self::MISSING_FIELD,
        'required_if'      => self::MISSING_FIELD,

        'email'        => self::INVALID_FORMAT,
        'date'         => self::INVALID_FORMAT,
        'date_format'  => self::INVALID_FORMAT,
        'string'       => self::INVALID_FORMAT,
        'integer'      => self::INVALID_FORMAT,
        'numeric'      => self::INVALID_FORMAT,
        'boolean'      => self::INVALID_FORMAT,
        'array'        => self::INVALID_FORMAT,
        'uuid'         => self::INVALID_FORMAT,

        'min'     => self::OUT_OF_RANGE,
        'max'     => self::OUT_OF_RANGE,
        'between' => self::OUT_OF_RANGE,
        'size'    => self::OUT_OF_RANGE,

        'in'     => self::INVALID_ENUM,
        'not_in' => self::INVALID_ENUM,

        'exists' => self::INVALID_RELATION,

        'unique' => self::DUPLICATE_FIELD,
    ];

    public static function fromValidationRule(string $rule): string
    {
        $baseName = explode(':', $rule)[0];

        return self::$ruleMapping[$baseName] ?? self::INVALID_FORMAT;
    }
}
