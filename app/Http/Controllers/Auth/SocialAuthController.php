<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Tymon\JWTAuth\Facades\JWTAuth;

class SocialAuthController extends Controller
{
    /**
     * Redirige al usuario al proveedor (Google, GitHub, Facebook).
     */
    public function redirect($provider)
    {
        if (! in_array($provider, ['google', 'github', 'facebook'])) {
            return response()->json(['error' => 'Proveedor no soportado'], 400);
        }

        return Socialite::driver($provider)->stateless()->redirect();
    }

    /**
     * Maneja el callback desde el proveedor y crea/inicia sesión del usuario.
     */
    public function callback($provider)
    {
        try {
            $socialUser = Socialite::driver($provider)->stateless()->user();

            DB::beginTransaction();

            // Verificar si ya existe cuenta social
            $socialAccount = SocialAccount::where('provider', $provider)
                ->where('provider_id', $socialUser->getId())
                ->first();

            if ($socialAccount) {
                $user = $socialAccount->user;
                $user->last_login_at = now();
                $user->save();
            } else {
                $user = User::firstOrCreate(
                    ['email' => $socialUser->getEmail()],
                    [
                        'name' => $socialUser->getName() ?? $socialUser->getNickname(),
                        'password' => Hash::make(Str::random(16)),
                        'avatar' => $socialUser->getAvatar(),
                        'email_verified_at' => now(),
                        'last_login_at' => now(),
                    ]
                );

                SocialAccount::create([
                    'user_id' => $user->id,
                    'provider' => $provider,
                    'provider_id' => $socialUser->getId(),
                    'token' => $socialUser->token,
                    'refresh_token' => $socialUser->refreshToken ?? null,
                ]);
            }

            DB::commit();

            // Generar JWT
            $token = JWTAuth::fromUser($user);

            // Retornar una vista HTML simple que envía el token al opener (frontend)
            // Esto permite que el popup se comunique con la ventana principal
            return response(
                "<html>
                    <head>
                        <title>Autenticación Exitosa</title>
                    </head>
                    <body>
                        <script>
                            const data = {
                                status: 'success',
                                user: " . json_encode($user) . ",
                                authorisation: {
                                    token: '" . $token . "',
                                    type: 'bearer'
                                }
                            };
                            
                            // Enviar datos a la ventana que abrió este popup
                            if (window.opener) {
                                window.opener.postMessage(data, '*'); // '*' permite cualquier origen (ajustar si es necesario)
                                window.close();
                            } else {
                                document.body.innerHTML = '<pre>' + JSON.stringify(data, null, 2) + '</pre>';
                            }
                        </script>
                        <p>Autenticación completada. Cerrando ventana...</p>
                    </body>
                </html>"
            );

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'error' => 'Error al autenticar con '.$provider,
                'details' => $e->getMessage(),
            ], 500);
        }
    }
}
