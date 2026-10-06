<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** FR-SEC-05 — audit_log hanya dibaca; tidak dapat diubah dari UI. */
class AuditLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'aksi' => $this->aksi,
            'user' => $this->whenLoaded('user', fn () => $this->user?->namaTampil()),
            'objek_tipe' => $this->objek_tipe ? class_basename($this->objek_tipe) : null,
            'objek_id' => $this->objek_id,
            'data_lama' => $this->data_lama,
            'data_baru' => $this->data_baru,
            'ip' => $this->ip,
            'waktu' => $this->waktu?->toIso8601String(),
        ];
    }
}
