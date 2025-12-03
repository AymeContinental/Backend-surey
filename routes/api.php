<?php

use App\Http\Controllers\Auth\LocalAuthController;
use App\Http\Controllers\Auth\SocialAuthController;
use App\Http\Controllers\FormController;
use App\Http\Controllers\QuizController;
use App\Http\Controllers\SurveyController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::get('{provider}/redirect', [SocialAuthController::class, 'redirect']);
    Route::get('{provider}/callback', [SocialAuthController::class, 'callback']);
    // Route::post('register', [LocalAuthController::class, 'register']); // POST para registrar
    Route::post('login', [LocalAuthController::class, 'login']); // POST sesion local
    Route::get('me', [LocalAuthController::class, 'me'])->middleware('auth:api'); // Obtener usuario actual
});

Route::get('/protected', function () {
    return response()->json(['message' => 'Access granted']);
})->middleware('jwt.auth'); // TEST de prueba protegida con JWT

// crear Survey
Route::middleware('auth:api')->group(function () {
    Route::post('/createSurvey', [SurveyController::class, 'createSurvey']);
});

// Devuelve los quiz y survey del usuario, con opción de filtrar por estado
Route::middleware('auth:api')->group(function () {
    Route::get('/myForms', [FormController::class, 'myForms']);
});

// Devuelve los quiz y survey RESUMIDOS del usuario, con opción de filtrar por estado
Route::middleware('auth:api')->group(function () {
    Route::get('/myFormsResume', [FormController::class, 'myFormsResume']);
    Route::get('/recentActivity', [FormController::class, 'recentActivity']);
    Route::get('/mySubmissions', [FormController::class, 'mySubmissions']);
});

// Eliminar formulario (Soft Delete)
Route::middleware('auth:api')->group(function () {
    Route::delete('/deleteForm/{id}', [FormController::class, 'deleteForm']);
});

// Actualizar survey
Route::middleware('auth:api')->group(function () {
    Route::put('/updateSurvey/{formId}', [SurveyController::class, 'updateSurvey']);
});

// ver un formulario
Route::middleware('auth:api')->group(function () {
    Route::get('/showForm/{formId}', [FormController::class, 'showForm']);
});

// ver  preguntas de un formulario
Route::middleware('auth:api')->group(function () {
    Route::get('/getQuestions/{code}', [FormController::class, 'getQuestions']);
});

// createQuiz
Route::middleware('auth:api')->group(function () {
    Route::post('/createQuiz', [QuizController::class, 'createQuiz']);
    Route::put('/updateQuiz/{formId}', [QuizController::class, 'updateQuiz']);
    Route::post('/submitQuiz/{code}', [QuizController::class, 'submitQuiz']);
    Route::get('/quiz/{id}/results', [QuizController::class, 'getResults']);
});

// Verificar permisos de formulario (público y si ya respondió)
Route::middleware('auth:api')->group(function () {
    Route::get('/permissionResolve/{code}', [FormController::class, 'permissionResolve']);
});

// Resultados y Respuestas
Route::middleware('auth:api')->group(function () {
    Route::post('/submit/{code}', [SurveyController::class, 'submitResponse']);
    Route::get('/survey/{id}/results', [SurveyController::class, 'getResults']);
    Route::delete('/submission/{id}', [SurveyController::class, 'deleteSubmission']);
});
