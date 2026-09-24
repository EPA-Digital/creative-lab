<?php

namespace App\Services;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * TOTP obligatorio para metodo_auth=password (auth-prompt.md Fase 3). Un
 * correo del dominio epa.digital nunca pasa por acá -- entra por Google
 * (ver GoogleAuthController).
 */
class TotpService
{
    private const EMISOR = 'zx-dashboard';

    private const CANTIDAD_CODIGOS_RECUPERACION = 8;

    public function __construct(private readonly Google2FA $google2fa) {}

    public function generarSecreto(): string
    {
        return $this->google2fa->generateSecretKey();
    }

    /**
     * SVG embebible directo en <img src="data:image/svg+xml;base64,...">
     * -- nunca se manda el secreto en texto plano al front más de lo
     * necesario para armar el QR (la vista de enrolamiento también
     * muestra el secreto en texto para quien no pueda escanear).
     */
    public function qrSvgParaSecreto(string $email, string $secreto): string
    {
        $otpauthUrl = $this->google2fa->getQRCodeUrl(self::EMISOR, $email, $secreto);

        $renderer = new ImageRenderer(new RendererStyle(200), new SvgImageBackEnd);

        return (new Writer($renderer))->writeString($otpauthUrl);
    }

    /**
     * Verifica un código TOTP contra el secreto del usuario, con
     * anti-replay: nunca acepta un código de un timestep ya usado (ver
     * totp_last_timestep). Si es válido, deja el nuevo timestep guardado
     * -- quien llama decide si además confirma el enrolamiento.
     */
    public function verificarCodigo(User $usuario, string $codigo): bool
    {
        if ($usuario->totp_secret === null) {
            return false;
        }

        $timestepValidado = $this->google2fa->verifyKeyNewer(
            $usuario->totp_secret,
            $codigo,
            $usuario->totp_last_timestep ?? 0,
        );

        if ($timestepValidado === false) {
            return false;
        }

        $usuario->update(['totp_last_timestep' => $timestepValidado]);

        return true;
    }

    /**
     * @return list<string> códigos en texto plano -- se muestran UNA sola
     *                      vez al usuario en el momento de enrolar. Lo
     *                      que se persiste (ver hashearCodigosRecuperacion)
     *                      son los hashes.
     */
    public function generarCodigosRecuperacion(): array
    {
        return collect(range(1, self::CANTIDAD_CODIGOS_RECUPERACION))
            ->map(fn () => Str::upper(Str::random(4)).'-'.Str::upper(Str::random(4)))
            ->all();
    }

    /**
     * @param  list<string>  $codigos
     * @return list<string>
     */
    public function hashearCodigosRecuperacion(array $codigos): array
    {
        return array_map(fn (string $codigo) => Hash::make($codigo), $codigos);
    }

    /**
     * De un solo uso: si coincide, lo saca de la lista antes de devolver
     * true. Quien llama es responsable de persistir a $usuario después.
     */
    public function verificarYConsumirCodigoRecuperacion(User $usuario, string $codigo): bool
    {
        $hashes = $usuario->totp_recovery_codes ?? [];

        foreach ($hashes as $indice => $hash) {
            if (Hash::check($codigo, $hash)) {
                unset($hashes[$indice]);
                $usuario->update(['totp_recovery_codes' => array_values($hashes)]);

                return true;
            }
        }

        return false;
    }
}
