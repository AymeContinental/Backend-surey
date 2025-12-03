<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Tymon\JWTAuth\Facades\JWTAuth;

class LocalAuthController extends Controller
{
    /**
     * Registra un nuevo usuario localmente.
     */
    public function register(Request $request)
    {
        // 1. Validación de los datos de entrada
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
        ]);

        // 2. Creación del usuario en la base de datos
        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password), // Se hashea la contraseña
            'email_verified_at' => now(), // Asumimos verificado al registrarse
            'last_login_at' => now(),
        ]);

        // 3. Generación del JWT para la sesión
        $token = JWTAuth::fromUser($user);

        // 4. Respuesta de éxito
        return response()->json([
            'status' => 'success',
            'message' => 'User successfully registered',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'provider' => 'local', // Indicador de tipo de login
            ],
            'authorisation' => [
                'token' => $token,
                'type' => 'bearer',
            ],
        ], 201);
    }

    /**
     * Inicia sesión del usuario localmente (Name y Email).
     */
    public function login(Request $request)
    {
        // 1. Validación de credenciales: Usando 'name' y 'email'
        $request->validate([
            'name' => 'required|string|max:255', // Nuevo campo para login
            'email' => 'required|email',
        ]);

        // 2. Buscar al usuario por el nombre proporcionado
        $user = User::where('name', $request->name)->first();

        // 3. Verificar si el usuario existe y si el email coincide
        // (Reemplaza JWTAuth::attempt por lógica manual ya que no usamos el campo 'password')
        if (! $user || $user->email !== $request->email) {
            // Usuario no encontrado o email no coincide
            return response()->json(['error' => 'Unauthorized: Invalid Name or Email'], 401);
        }

        // 4. autenticacionexitosa genera JWT
        $token = JWTAuth::fromUser($user);

        // 5. Actualizar ultimo login
        $user->last_login_at = now();
        $user->save();

        // 6. Respuesta de éxito
        return response()->json([
            'status' => 'success',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'provider' => 'local-name-email', // Indicador de tipo de login
            ],
            'authorisation' => [
                'token' => $token,
                'type' => 'bearer',
            ],
        ]);
    }
    /**
     * Obtener usuario autenticado con estadísticas.
     */
    public function me()
    {
        $user = auth()->user();

        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'avatar' => $user->avatar,
            'created_at' => $user->created_at,
            'quizzes_count' => $user->forms()->where('type', 'quiz')->count(),
            'surveys_count' => $user->forms()->where('type', 'survey')->count(),
            'quizzes_taken' => $user->submissions()->whereHas('form', function($q) { $q->where('type', 'quiz'); })->count(),
            'surveys_taken' => $user->submissions()->whereHas('form', function($q) { $q->where('type', 'survey'); })->count(),
        ]);
    }
}
