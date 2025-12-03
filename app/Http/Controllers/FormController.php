<?php

namespace App\Http\Controllers;

use App\Models\Form;
use App\Models\Question;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FormController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:api');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'status' => 'nullable|in:publico,privado,borrador,desabilitado',
            'type' => 'required|in:quiz,survey',
            'questions' => 'required|array|min:1',
        ]);

        $data['code'] = strtoupper(Str::random(8));
        $data['user_id'] = Auth::id();
        $data['status'] = $data['status'] ?? 'borrador';

        DB::beginTransaction();
        try {
            $form = Form::create($data);

            foreach ($data['questions'] as $key => $q) {
                $question = new Question([
                    'type' => $q['type'],
                    'text' => $q['text'],
                    'required' => $q['required'] ?? true,
                    'placeholder' => $q['placeholder'] ?? null,
                    'min_length' => $q['min_length'] ?? null,
                    'max_length' => $q['max_length'] ?? null,
                    'min_selection' => $q['min_selection'] ?? null,
                    'max_selection' => $q['max_selection'] ?? null,
                    'allowed_file_types' => isset($q['allowed_file_types']) ? implode(',', $q['allowed_file_types']) : null,
                    'scale_min' => $q['min'] ?? null,
                    'scale_max' => $q['max'] ?? null,
                    'scale_label_min' => $q['labels']['min'] ?? null,
                    'scale_label_max' => $q['labels']['max'] ?? null,
                ]);
                $form->questions()->save($question);

                // Opciones
                if (isset($q['options'])) {
                    foreach ($q['options'] as $opt) {
                        $question->options()->create([
                            'text' => is_array($opt) ? $opt['text'] : $opt,
                            'is_correct' => is_array($opt) ? ($opt['is_correct'] ?? false) : false,
                        ]);
                    }
                }

                // Adjuntos (Supabase)
                if (isset($q['attachments']) && is_array($q['attachments'])) {
                    $supabase = new \App\Services\SupabaseService();
                    foreach ($q['attachments'] as $file) {
                        // Assuming $file is an UploadedFile or base64? 
                        // If it's a file upload via multipart/form-data, it would be in $request->file().
                        // But here we are iterating over $data['questions'].
                        // If the request is JSON, we can't easily upload files unless they are base64.
                        // If the request is multipart, the structure is different.
                        // Given the context of "API", it's likely JSON.
                        // However, standard file uploads in Laravel are via $request->file().
                        // Let's assume for now we are handling base64 or that the user will adapt the frontend.
                        // BUT, the user said "modifica el proyecto pra que use supabase".
                        // If the frontend sends files, it usually uses FormData.
                        // If using FormData, `questions[0][image]` would be the file.
                        
                        // Let's check how to handle this. 
                        // If $q['attachments'] contains files, we can upload them.
                        // But $data is validated from $request->validate.
                        // If files are sent, they are in $request->all() or $request->file().
                        
                        // Let's try to handle it if it's an UploadedFile object (which Laravel validation might pass through if configured, but usually we access $request->file).
                        
                        // Actually, simpler approach:
                        // If the user sends a file, we upload it.
                        // I will add logic to check if there is a file.
                    }
                }
                
                // Wait, I can't easily iterate files inside a JSON structure validation result.
                // I will add a comment or a placeholder, OR I will assume the user sends a separate request or uses multipart/form-data where `questions[index][attachments]` are files.
                
                // Let's look at the `update` method in `QuizController` the user modified.
                // "// Si en el futuro deseas guardar archivos subidos aquí"
                // The user left a comment.
                
                // I will implement the logic assuming `questions.*.attachments` can be files.
                // But I need to access the request files directly.
                
                if ($request->hasFile("questions.{$key}.attachments")) {
                     $supabase = new \App\Services\SupabaseService();
                     $files = $request->file("questions.{$key}.attachments");
                     // Ensure it's an array (handle single file upload case if needed, though usually multiple is array)
                     if (!is_array($files)) {
                         $files = [$files];
                     }
                     foreach ($files as $file) {
                         $url = $supabase->uploadFile($file, 'attachments');
                         if ($url) {
                             $question->attachments()->create([
                                 'file_path' => $url,
                                 'file_type' => $file->getMimeType()
                             ]);
                         }
                     }
                }
            } // End foreach questions

            DB::commit();

            return response()->json([
                'message' => 'Form creado correctamente',
                'form_code' => $form->code,
                'form_id' => $form->id,
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'error' => 'Error al crear el form',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    // Todos los datos del formulario
    public function myForms(Request $request)
    {
        $userId = Auth::id();

        // Filtros opcionales
        $status = $request->query('status');
        $type = $request->query('type');     // 'quiz', 'survey' o null para todo

        $query = \App\Models\Form::with(['questions.options', 'questions.attachments'])
            ->where('user_id', $userId);

        // Aplicar filtro de status si es válido
        if ($status && in_array($status, ['publico', 'privado', 'borrador', 'desabilitado'])) {
            $query->where('status', $status);
        }

        // Aplicar filtro de tipo si es válido
        if ($type && in_array($type, ['quiz', 'survey'])) {
            $query->where('type', $type);
        }

        $forms = $query->orderBy('created_at', 'desc')->get();

        return response()->json([
            'data' => $forms,
        ]);
    }

    public function permissionResolve(Request $request, $code)
    {
        $userId = Auth::id();

        // 1. Buscar form por código y status público
        $form = \App\Models\Form::where('code', $code)
            ->where('status', 'publico')
            ->first();
        if (!$form) {
            return response()->json(['error' => 'Formulario no encontrado o no disponible'], 404);
        }

        // 2. Verificar si ya respondió
        // Si el usuario está autenticado, verificamos en submissions
        if ($userId) {
            $submission = \App\Models\Submission::where('form_id', $form->id)
                ->where('user_id', $userId)
                ->first();

            if ($submission) {
                return response()->json([
                    'status' => 'ya respondido',
                    'title' => $form->title,
                    'description' => $form->description,
                    'type' => $form->type
                ]);
            }
        }

        // 3. Permitido
        return response()->json([
            'status' => 'permitted',
            'title' => $form->title,
            'description' => $form->description,
            'type' => $form->type
        ]);
    }
    public function myFormsResume(Request $request)
    {
        $userId = Auth::id();

        // Filtros opcionales
        $status = $request->query('status');
        $type = $request->query('type');

        $query = \App\Models\Form::where('user_id', $userId)
            ->where('status', '!=', 'eliminado'); // Excluir eliminados

        // Aplicar filtro de status si es válido
        if ($status && in_array($status, ['publico', 'privado', 'borrador', 'desabilitado'])) {
            $query->where('status', $status);
        }

        // Aplicar filtro de tipo si es válido
        if ($type && in_array($type, ['quiz', 'survey'])) {
            $query->where('type', $type);
        }

        $forms = $query
            // Selecciona explícitamente solo los campos que necesitas, incluyendo 'type'
            ->select('id', 'title', 'status', 'code', 'type', 'updated_at')
            ->withCount('submissions as answered')
            // Usa withCount para obtener el número de preguntas sin cargar sus datos
            ->withCount('questions')
            ->orderBy('updated_at', 'desc') // Ordenar por fecha de actualización
            ->get();

        return response()->json([
            'data' => $forms,
        ]);
    }

    public function deleteForm($id)
    {
        $userId = Auth::id();

        $form = Form::where('id', $id)
            ->where('user_id', $userId)
            ->first();

        if (! $form) {
            return response()->json(['error' => 'Formulario no encontrado o no tienes permiso'], 404);
        }

        // Soft delete: cambiar status a 'eliminado'
        $form->status = 'eliminado';
        $form->touch(); // Actualiza updated_at
        $form->save();

        return response()->json(['message' => 'Formulario eliminado correctamente']);
    }

    public function update(Request $request, $formId)
    {
        $userId = Auth::id();

        // Validar entrada
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'status' => 'nullable|in:publico,privado,borrador,desabilitado',
            'type' => 'required|in:quiz,survey',
            'questions' => 'required|array|min:1',
        ]);

        DB::beginTransaction();
        try {
            // Buscar form del usuario
            $form = Form::where('id', $formId)
                ->where('user_id', $userId)
                ->firstOrFail();

            // Actualizar form
            $form->update([
                'title' => $data['title'],
                'description' => $data['description'] ?? $form->description,
                'status' => $data['status'] ?? $form->status,
                'type' => $data['type'],
            ]);

            // Borrar preguntas y opciones previas
            foreach ($form->questions as $q) {
                $q->options()->delete();
                $q->attachments()->delete();
            }
            $form->questions()->delete();

            // Crear preguntas nuevamente
            foreach ($data['questions'] as $key => $q) {

                $question = $form->questions()->create([
                    'type' => $q['type'],
                    'text' => $q['text'],
                    'required' => $q['required'] ?? true,
                    'placeholder' => $q['placeholder'] ?? null,
                    'min_length' => $q['min_length'] ?? null,
                    'max_length' => $q['max_length'] ?? null,
                    'min_selection' => $q['min_selection'] ?? null,
                    'max_selection' => $q['max_selection'] ?? null,
                    'allowed_file_types' => isset($q['allowed_file_types']) ? implode(',', $q['allowed_file_types']) : null,
                    'scale_min' => $q['scale_min'] ?? null,
                    'scale_max' => $q['scale_max'] ?? null,
                    'scale_label_min' => $q['scale_label_min'] ?? null,
                    'scale_label_max' => $q['scale_label_max'] ?? null,

                ]);

                // Opciones (si aplica)
                if (isset($q['options'])) {
                    foreach ($q['options'] as $opt) {
                        $question->options()->create([
                            'text' => is_array($opt) ? $opt['text'] : $opt,
                            'is_correct' => is_array($opt) ? ($opt['is_correct'] ?? false) : false,
                        ]);
                    }
                }

                // Adjuntos (Supabase)
                if ($request->hasFile("questions.{$key}.attachments")) {
                     $supabase = new \App\Services\SupabaseService();
                     $files = $request->file("questions.{$key}.attachments");
                     if (!is_array($files)) {
                         $files = [$files];
                     }
                     foreach ($files as $file) {
                         $url = $supabase->uploadFile($file, 'attachments');
                         if ($url) {
                             $question->attachments()->create([
                                 'file_path' => $url,
                                 'file_type' => $file->getMimeType()
                             ]);
                         }
                     }
                }
            }

            DB::commit();

            return response()->json([
                'message' => 'Formulario actualizado correctamente',
                'form_id' => $formId,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'error' => 'Error al actualizar formulario',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    public function showForm($formId)
    {
        $userId = Auth::id();

        // Obtener formulario del usuario con sus relaciones
        $form = Form::with([
            'questions.options',
            'questions.attachments',
        ])
            ->where('id', $formId)
            ->where('user_id', $userId)
            ->first();

        if (! $form) {
            return response()->json([
                'error' => 'Formulario no encontrado o no tienes acceso',
            ], 404);
        }

        return response()->json($form);
    }

    public function getQuestions($code)
    {
        // Buscar el formulario por su código, pero solo si es público
        $form = Form::with([
            'questions.options' => function ($query) {
                // Excluimos el campo 'is_correct' de las opciones
                $query->select('id', 'question_id', 'text', 'score');
            },
            'questions.attachments' => function ($query) {
                // Incluimos los campos relevantes del adjunto
                $query->select('id', 'question_id', 'file_path', 'file_type');
            },
        ])
            ->where('code', $code)
            ->where('status', 'publico')
            ->first();

        // Si no existe o no es público
        if (! $form) {
            return response()->json([
                'error' => 'Formulario no encontrado o no es público',
            ], 404);
        }

        // Excluir is_correct de las opciones (por seguridad) y answer/score de las preguntas
        $form->questions->each(function ($question) {
            unset($question->answer);
            unset($question->score);
            
            $question->options->transform(function ($opt) {
                unset($opt->is_correct);
                return $opt;
            });
        });

        return response()->json([
            'title' => $form->title,
            'description' => $form->description,
            'code' => $form->code,
            'type' => $form->type,
            'questions' => $form->questions,
        ]);
    }
    
    public function recentActivity(Request $request)
    {
        $userId = Auth::id();

        $submissions = \App\Models\Submission::with(['form.questions.options', 'responses.question.options'])
            ->where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->take(8)
            ->get();

        $activity = $submissions->map(function ($sub) {
            $form = $sub->form;
            // If form is deleted or null, handle it
            if (!$form) return null;

            $score = 0;
            $maxScore = 0;

            if ($form->type === 'quiz') {
                // Calculate Max Score
                foreach ($form->questions as $q) {
                     if (in_array($q->type, ['text-input', 'date'])) {
                        $maxScore += $q->total_score ?? 0;
                     } else {
                        $qMax = $q->options->where('is_correct', true)->sum('score');
                        if ($qMax == 0) $qMax = $q->total_score ?? 0;
                        $maxScore += $qMax;
                     }
                }

                // Calculate User Score
                foreach ($sub->responses as $resp) {
                    $q = $resp->question;
                    if (!$q) continue;

                    $points = 0;
                    $userAnswer = $resp->answer_text;

                    if ($q->type === 'text-input' || $q->type === 'date') {
                         $validAnswers = json_decode($q->answer, true);
                         if (!is_array($validAnswers)) $validAnswers = [$q->answer];
                         
                         $normalizedUserAnswer = trim(strtolower($userAnswer));
                         $normalizedValidAnswers = array_map(function($a) { return trim(strtolower($a)); }, $validAnswers);
                         
                         if (in_array($normalizedUserAnswer, $normalizedValidAnswers)) {
                             $points = $q->total_score ?? 0;
                         }

                    } elseif (in_array($q->type, ['multiple-choice', 'checkbox', 'radio-button', 'dropdown'])) {
                        $selectedTexts = json_decode($userAnswer, true);
                        if (!is_array($selectedTexts)) $selectedTexts = [$userAnswer];

                        $correctOptions = $q->options->where('is_correct', true);
                        $correctTexts = $correctOptions->pluck('text')->toArray();

                        if ($q->required) {
                            $allCorrectSelected = !array_diff($correctTexts, $selectedTexts) && !array_diff($selectedTexts, $correctTexts);
                            if ($allCorrectSelected) {
                                $points = $correctOptions->sum('score');
                            }
                        } else {
                            $selectedOptions = $q->options->whereIn('text', $selectedTexts);
                            foreach ($selectedOptions as $opt) {
                                if ($opt->is_correct) {
                                    $points += $opt->score ?? 0;
                                }
                            }
                        }
                    } elseif ($q->type === 'scale') {
                         $correctValue = $q->answer;
                         $correctValue = is_numeric($correctValue) ? (int)$correctValue : null;
                         if ($correctValue !== null && (int)$userAnswer === $correctValue) {
                             $points = $q->total_score ?? 0;
                         }
                    }
                    $score += $points;
                }
            }

            return [
                'id' => $sub->id,
                'form_title' => $form->title,
                'form_type' => $form->type,
                'created_at' => $sub->created_at,
                'score' => $score,
                'max_score' => $maxScore,
                'is_perfect' => ($form->type === 'quiz' && $score === $maxScore && $maxScore > 0)
            ];
        })->filter(); // Remove nulls

        return response()->json($activity->values());
    }

    public function mySubmissions(Request $request)
    {
        $userId = Auth::id();
        $type = $request->query('type'); // 'quiz', 'survey'
        $search = $request->query('search');
        $sort = $request->query('sort', 'date_desc'); // date_asc, date_desc, alpha_asc, alpha_desc

        $query = \App\Models\Submission::with(['form.user', 'form.questions.options', 'responses.question.options'])
            ->where('user_id', $userId);

        // Filter by Type
        if ($type && in_array($type, ['quiz', 'survey'])) {
            $query->whereHas('form', function ($q) use ($type) {
                $q->where('type', $type);
            });
        }

        // Filter by Search
        if ($search) {
            $query->whereHas('form', function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%");
            });
        }

        // Sorting
        switch ($sort) {
            case 'date_asc':
                $query->orderBy('created_at', 'asc');
                break;
            case 'alpha_asc':
                $query->join('forms', 'submissions.form_id', '=', 'forms.id')
                      ->orderBy('forms.title', 'asc')
                      ->select('submissions.*'); // Avoid column collisions
                break;
            case 'alpha_desc':
                $query->join('forms', 'submissions.form_id', '=', 'forms.id')
                      ->orderBy('forms.title', 'desc')
                      ->select('submissions.*');
                break;
            case 'date_desc':
            default:
                $query->orderBy('created_at', 'desc');
                break;
        }

        $submissions = $query->paginate(10);

        // Transform data
        $submissions->getCollection()->transform(function ($sub) {
            $form = $sub->form;
            if (!$form) return null;

            $score = 0;
            $maxScore = 0;
            $correctCount = 0;
            $incorrectCount = 0;

            if ($form->type === 'quiz') {
                // Calculate Max Score
                foreach ($form->questions as $q) {
                     if (in_array($q->type, ['text-input', 'date'])) {
                        $maxScore += $q->total_score ?? 0;
                     } else {
                        $qMax = $q->options->where('is_correct', true)->sum('score');
                        if ($qMax == 0) $qMax = $q->total_score ?? 0;
                        $maxScore += $qMax;
                     }
                }

                // Calculate User Score
                foreach ($sub->responses as $resp) {
                    $q = $resp->question;
                    if (!$q) continue;

                    $points = 0;
                    $userAnswer = $resp->answer_text;
                    $isCorrect = false;

                    if ($q->type === 'text-input' || $q->type === 'date') {
                         $validAnswers = json_decode($q->answer, true);
                         if (!is_array($validAnswers)) $validAnswers = [$q->answer];
                         
                         $normalizedUserAnswer = trim(strtolower($userAnswer));
                         $normalizedValidAnswers = array_map(function($a) { return trim(strtolower($a)); }, $validAnswers);
                         
                         if (in_array($normalizedUserAnswer, $normalizedValidAnswers)) {
                             $points = $q->total_score ?? 0;
                             $isCorrect = true;
                         }

                    } elseif (in_array($q->type, ['multiple-choice', 'checkbox', 'radio-button', 'dropdown'])) {
                        $selectedTexts = json_decode($userAnswer, true);
                        if (!is_array($selectedTexts)) $selectedTexts = [$userAnswer];

                        $correctOptions = $q->options->where('is_correct', true);
                        $correctTexts = $correctOptions->pluck('text')->toArray();

                        if ($q->required) {
                            $allCorrectSelected = !array_diff($correctTexts, $selectedTexts) && !array_diff($selectedTexts, $correctTexts);
                            if ($allCorrectSelected) {
                                $points = $correctOptions->sum('score');
                                $isCorrect = true;
                            }
                        } else {
                            // Partial credit logic? Assuming full correct for simplicity of "isCorrect" flag
                            // But let's stick to points > 0 means "some correctness"
                            $selectedOptions = $q->options->whereIn('text', $selectedTexts);
                            $currentPoints = 0;
                            foreach ($selectedOptions as $opt) {
                                if ($opt->is_correct) {
                                    $currentPoints += $opt->score ?? 0;
                                }
                            }
                            $points = $currentPoints;
                            if ($points > 0) $isCorrect = true; 
                        }
                    } elseif ($q->type === 'scale') {
                         $correctValue = $q->answer;
                         $correctValue = is_numeric($correctValue) ? (int)$correctValue : null;
                         if ($correctValue !== null && (int)$userAnswer === $correctValue) {
                             $points = $q->total_score ?? 0;
                             $isCorrect = true;
                         }
                    }
                    $score += $points;
                    
                    if ($isCorrect) $correctCount++;
                    else $incorrectCount++;
                }
            }

            // Prepare responses array
            $responsesArray = $sub->responses->map(function ($resp) {
                return [
                    'id' => $resp->id,
                    'question_text' => $resp->question ? $resp->question->text : 'Pregunta eliminada',
                    'answer_text' => $resp->answer_text
                ];
            });

            return [
                'id' => $sub->id,
                'form_id' => $form->id,
                'title' => $form->title,
                'type' => $form->type,
                'author' => $form->user ? $form->user->name : 'Desconocido',
                'submitted_at' => $sub->created_at,
                'score' => $score,
                'max_score' => $maxScore,
                'correct_answers' => $correctCount,
                'incorrect_answers' => $incorrectCount,
                'percentage' => $maxScore > 0 ? round(($score / $maxScore) * 100) : 0,
                'responses' => $responsesArray
            ];
        });

        return response()->json($submissions);
    }
}
