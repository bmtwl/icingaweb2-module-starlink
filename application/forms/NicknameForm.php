<?php

namespace Icinga\Module\Starlink\Forms;

use Icinga\Module\Starlink\Db;
use ipl\Validator\CallbackValidator;
use ipl\Web\Compat\CompatForm;

class NicknameForm extends CompatForm
{
    const MAX_LENGTH = 64;

    /** @var string */
    protected $deviceId;

    /** @var ?string */
    protected $current;

    public function __construct(string $deviceId, ?string $current)
    {
        $this->deviceId = $deviceId;
        $this->current = $current;
    }

    /** Collapse whitespace; empty means "no nickname" */
    public static function normalize($value): ?string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', (string) $value));

        return $value === '' ? null : $value;
    }

    protected function assemble()
    {
        $this->addElement('text', 'nickname', [
            'label'       => mt('starlink', 'Nickname'),
            'description' => mt('starlink', 'Shown instead of the device ID and exported to Director.'
                . ' Must be unique (case-insensitive). Leave empty to remove.'),
            'value'       => $this->current,
            'validators'  => [new CallbackValidator(function ($value, CallbackValidator $validator) {
                $value = static::normalize($value);
                if ($value === null) {
                    return true;
                }
                if (mb_strlen($value) > self::MAX_LENGTH) {
                    $validator->addMessage(sprintf(
                        mt('starlink', 'At most %d characters'),
                        self::MAX_LENGTH
                    ));
                    return false;
                }
                if (preg_match('/[\x00-\x1F\x7F]/u', $value)) {
                    $validator->addMessage(mt('starlink', 'Control characters are not allowed'));
                    return false;
                }
                $other = Db::value(
                    'SELECT device_id FROM starlink_terminal WHERE lower(nickname) = lower(?) AND device_id <> ?',
                    [$value, $this->deviceId]
                );
                if ($other !== null) {
                    $validator->addMessage(sprintf(mt('starlink', 'Already used by %s'), $other));
                    return false;
                }

                return true;
            })],
        ]);
        $this->addElement('submit', 'btn_submit', ['label' => mt('starlink', 'Save')]);
    }

    protected function onSuccess()
    {
        Db::exec(
            'UPDATE starlink_terminal SET nickname = ? WHERE device_id = ?',
            [static::normalize($this->getValue('nickname')), $this->deviceId]
        );
    }
}
