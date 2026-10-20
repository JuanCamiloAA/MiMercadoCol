<?php

namespace App\Realtime;

use App\Events\ResourcesChanged;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Junta lo que cambio durante una peticion (o un trabajo de la cola) y lo transmite una sola vez,
 * al final.
 *
 * Una venta toca la venta, sus pagos, cinco filas de existencias y cinco movimientos: transmitir
 * cada guardado serian doce avisos para decir una sola cosa. Aqui se juntan por empresa, tema y
 * sede, y sale un aviso por empresa cuando la peticion ya respondio.
 *
 * **En una peticion GET no se junta nada.** Lo que se transmite hace que las otras pestañas
 * recarguen, y recargar es un GET: si un GET que guarda algo de paso (la ultima actividad del
 * usuario, una fila de existencias que nace en cero) transmitiera, dos pestañas abiertas se
 * recargarian la una a la otra para siempre.
 */
class ChangeCollector
{
    /** @var array<int, array<string, array<int, true>>> empresa => tema => sede (0 = toda la empresa) */
    protected array $pending = [];

    public function recordModel(Model $model): void
    {
        $topic = Topics::forModel($model);

        if ($topic === null || ! $this->listening()) {
            return;
        }

        $companyId = (int) ($model->getAttribute('company_id') ?? 0) ?: TenantContext::activeCompanyId();
        $storeId = Topics::isStoreScoped($topic) ? (int) ($model->getAttribute('store_id') ?? 0) : 0;

        if ($companyId) {
            $this->pending[(int) $companyId][$topic][$storeId] = true;
        }
    }

    /**
     * Para lo que se escribe sin pasar por el modelo (un `update` masivo): dice que cambio.
     */
    public function record(int $companyId, string $topic, ?int $storeId = null): void
    {
        if (! $this->listening()) {
            return;
        }

        $this->pending[$companyId][$topic][Topics::isStoreScoped($topic) ? (int) $storeId : 0] = true;
    }

    /** Transmite lo juntado y queda vacio. Si el tiempo real esta apagado, solo se vacia. */
    public function flush(): void
    {
        if ($this->pending === []) {
            return;
        }

        $pending = $this->pending;
        $this->pending = [];

        if (! config('broadcasting.changes', true) || config('broadcasting.default') === 'null') {
            return;
        }

        $userId = Auth::guard('web')->id();

        foreach ($pending as $companyId => $topics) {
            $changes = [];

            foreach ($topics as $topic => $stores) {
                $changes[] = [
                    'topic' => $topic,
                    // null es «toda la empresa»: le interesa a cualquier sede.
                    'stores' => array_map(fn (int $store) => $store === 0 ? null : $store, array_keys($stores)),
                ];
            }

            // `toOthers`: la pestaña que hizo el cambio ya tiene la respuesta; no se recarga a si misma.
            broadcast(new ResourcesChanged($companyId, $changes, $userId))->toOthers();
        }
    }

    /** @return array<int, array<string, array<int, true>>> */
    public function pending(): array
    {
        return $this->pending;
    }

    protected function listening(): bool
    {
        // La cola y los comandos no tienen peticion: lo que guardan si es un cambio.
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return true;
        }

        return ! request()->isMethodSafe();
    }
}
