<?php

namespace Tests\Feature;

use App\Models\MediaAsset;
use App\Models\Product;
use App\Providers\AppServiceProvider;
use App\Support\Storage\FirebaseStorageAdapter;
use App\Support\Uploads;
use Google\Cloud\Storage\Bucket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Config;
use RuntimeException;
use Tests\Concerns\CreatesCompanyEnvironment;
use Tests\TestCase;

/**
 * Firebase Storage sin salir a internet: lo publico se escribe con su token de descarga y se
 * enlaza por Firebase; lo privado no lleva token ni enlace; y la aplicacion guarda en el disco
 * configurado, no en uno fijo.
 */
class FirebaseStorageTest extends TestCase
{
    use CreatesCompanyEnvironment, RefreshDatabase;

    public function test_lo_publico_se_escribe_con_su_token_y_lo_privado_sin_el(): void
    {
        $bucket = $this->recordingBucket();
        $public = new FirebaseStorageAdapter($bucket, 'public', 'token-de-prueba');
        $private = new FirebaseStorageAdapter($bucket, 'private', null);

        $public->write('products/1/foto.jpg', 'contenido', new Config);
        $private->write('customer-returns/1/foto.jpg', 'contenido', new Config(['metadata' => ['contentType' => 'image/jpeg']]));

        [$first, $second] = $bucket->uploads;

        $this->assertSame('public/products/1/foto.jpg', $first['name']);
        $this->assertSame('token-de-prueba', $first['metadata']['metadata']['firebaseStorageDownloadTokens']);
        $this->assertStringStartsWith('public', $first['metadata']['cacheControl']);
        $this->assertArrayNotHasKey('predefinedAcl', $first);

        $this->assertSame('private/customer-returns/1/foto.jpg', $second['name']);
        $this->assertArrayNotHasKey('metadata', $second['metadata']);
        $this->assertSame('image/jpeg', $second['metadata']['contentType']);
    }

    public function test_el_enlace_publico_es_el_de_descarga_de_firebase_y_lo_privado_no_tiene(): void
    {
        $bucket = $this->recordingBucket();

        $this->assertSame(
            'https://firebasestorage.googleapis.com/v0/b/mitiendacol.firebasestorage.app/o/public%2Fproducts%2F1%2Fmi%20foto.jpg?alt=media&token=abc',
            (new FirebaseStorageAdapter($bucket, 'public', 'abc'))->getUrl('products/1/mi foto.jpg'),
        );

        $this->expectException(RuntimeException::class);
        (new FirebaseStorageAdapter($bucket, 'private', null))->getUrl('customer-returns/1/foto.jpg');
    }

    public function test_el_disco_firebase_queda_cableado_con_la_llave_y_el_bucket(): void
    {
        $this->configureFirebase();

        $url = Storage::disk('firebase')->url('products/1/foto.jpg');
        $token = AppServiceProvider::firebaseDownloadToken(config('filesystems.disks.firebase'));

        $this->assertSame(FirebaseStorageAdapter::downloadUrl('mitiendacol.firebasestorage.app', 'public/products/1/foto.jpg', $token), $url);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $token);

        // Con la aplicacion en Firebase, todo enlace sale de ahi; una foto externa se deja tal cual.
        config(['filesystems.uploads' => 'firebase']);
        $this->assertTrue(Uploads::usesFirebase());
        $this->assertSame($url, Uploads::url('products/1/foto.jpg'));
        $this->assertSame('https://images.openfoodfacts.org/x.jpg', Uploads::url('https://images.openfoodfacts.org/x.jpg'));
        $this->assertNull(Uploads::url(null));

        // El token del .env manda sobre el que sale de la llave de la aplicacion.
        config(['filesystems.disks.firebase.download_token' => 'token-propio']);
        Storage::forgetDisk('firebase');
        $this->assertStringEndsWith('token=token-propio', Storage::disk('firebase')->url('a.jpg'));
    }

    public function test_sin_bucket_dice_que_falta(): void
    {
        $this->configureFirebase();
        config(['filesystems.disks.firebase.bucket' => '']);
        Storage::forgetDisk('firebase');

        $this->expectExceptionMessage('Falta FIREBASE_STORAGE_BUCKET');
        Storage::disk('firebase');
    }

    public function test_en_local_el_enlace_sigue_siendo_el_de_siempre(): void
    {
        $this->assertSame('public', Uploads::diskName());
        $this->assertSame('/storage/products/1/foto.jpg', Uploads::url('products/1/foto.jpg'));
        $this->assertFalse(Uploads::usesFirebase());
    }

    public function test_las_fotos_de_un_articulo_van_al_disco_configurado(): void
    {
        $this->seedEnvironment();
        config(['filesystems.uploads' => 'firebase']);
        Storage::fake('firebase');
        Storage::fake('public');

        $this->actingAs($this->makeUser('admin'))->withSession($this->withActiveStore())
            ->post(route('products.store'), [
                'category_id' => $this->category->id, 'name' => 'Café molido 500 g', 'sku' => 'CAF-001', 'unit' => 'pack',
                'cost' => 16500, 'price' => 22900, 'tax_rate' => 5, 'min_stock' => 10,
                'images' => [UploadedFile::fake()->create('portada.jpg', 120, 'image/jpeg')],
            ])->assertSessionHasNoErrors();

        $product = Product::query()->withoutGlobalScopes()->where('sku', 'CAF-001')->firstOrFail();

        Storage::disk('firebase')->assertExists($product->image_path);
        Storage::disk('public')->assertMissing($product->image_path);
    }

    public function test_la_migracion_sube_lo_publico_y_los_adjuntos_y_salta_lo_que_ya_esta(): void
    {
        $this->seedEnvironment();
        foreach (['public', 'local', 'firebase', 'firebase_private'] as $disk) {
            Storage::fake($disk);
        }

        Storage::disk('public')->put('products/1/foto.jpg', 'foto');
        Storage::disk('public')->put('media/1/banner.jpg', 'banner');
        Storage::disk('local')->put('customer-returns/1/rota.jpg', 'rota');
        Storage::disk('local')->put('respaldos/canasta.sql', 'respaldo');
        Storage::disk('firebase')->put('media/1/banner.jpg', 'banner');
        $asset = MediaAsset::query()->withoutGlobalScopes()->create(['company_id' => $this->company->id, 'path' => 'media/1/banner.jpg', 'disk' => 'public', 'alt' => 'Banner']);

        $this->artisan('uploads:firebase', ['--dry-run' => true])->assertSuccessful();
        Storage::disk('firebase')->assertMissing('products/1/foto.jpg');
        $this->assertSame('public', $asset->fresh()->disk);

        // El banner ya estaba arriba con el mismo tamaño; el respaldo de la base no es un adjunto.
        $this->artisan('uploads:firebase')
            ->expectsTable(['Subidos', 'Ya estaban', 'Fallaron', 'Megas subidos'], [[2, 1, 0, '0,0']])
            ->assertSuccessful();

        Storage::disk('firebase')->assertExists('products/1/foto.jpg');
        Storage::disk('firebase_private')->assertExists('customer-returns/1/rota.jpg');
        Storage::disk('firebase_private')->assertMissing('respaldos/canasta.sql');
        Storage::disk('public')->assertExists('products/1/foto.jpg');
        $this->assertSame('firebase', $asset->fresh()->disk);
        $this->assertSame(Storage::disk('firebase')->url('media/1/banner.jpg'), $asset->fresh()->url());
    }

    /** Un bucket que solo anota lo que se le pide subir: nada sale a internet. */
    protected function recordingBucket(): Bucket
    {
        return new class extends Bucket
        {
            /** @var list<array<string, mixed>> */
            public array $uploads = [];

            public function __construct() {}

            public function name()
            {
                return 'mitiendacol.firebasestorage.app';
            }

            public function upload($data, array $options = [])
            {
                $this->uploads[] = $options;

                return null;
            }
        };
    }

    /** Una llave de servicio de mentira: armar el cliente y el enlace no firma nada. */
    protected function configureFirebase(): void
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'firebase-prueba-'.getmypid().'.json';
        $this->beforeApplicationDestroyed(fn () => @unlink($path));
        file_put_contents($path, json_encode([
            'type' => 'service_account',
            'project_id' => 'mitiendacol',
            'private_key_id' => 'prueba',
            'private_key' => "-----BEGIN PRIVATE KEY-----\nprueba\n-----END PRIVATE KEY-----\n",
            'client_email' => 'prueba@mitiendacol.iam.gserviceaccount.com',
            'client_id' => '1',
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ]));

        config([
            'filesystems.disks.firebase.credentials' => $path,
            'filesystems.disks.firebase.bucket' => 'mitiendacol.firebasestorage.app',
            'filesystems.disks.firebase.download_token' => null,
        ]);
        Storage::forgetDisk('firebase');
    }
}
