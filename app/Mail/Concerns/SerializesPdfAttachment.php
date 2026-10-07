<?php

namespace App\Mail\Concerns;

use Illuminate\Queue\SerializesModels;
use UnexpectedValueException;

/** Preserve a rendered PDF snapshot across the queue's UTF-8 JSON payload. */
trait SerializesPdfAttachment
{
    use SerializesModels {
        __serialize as private serializeModels;
        __unserialize as private unserializeModels;
    }

    public function __serialize(): array
    {
        $values = $this->serializeModels();

        if (isset($values['pdf'])) {
            // Encode only in transit: existing callers and attachments() use bytes.
            $values['pdfBase64'] = base64_encode($values['pdf']);
            unset($values['pdf']);
        }

        return $values;
    }

    public function __unserialize(array $values): void
    {
        if (isset($values['pdfBase64'])) {
            $pdf = base64_decode($values['pdfBase64'], true);
            if ($pdf === false) {
                throw new UnexpectedValueException('Invalid encoded PDF attachment.');
            }
            $values['pdf'] = $pdf;
            unset($values['pdfBase64']);
        }

        // Older payloads carrying raw bytes or no attachment still restore normally.
        $this->unserializeModels($values);
    }
}
