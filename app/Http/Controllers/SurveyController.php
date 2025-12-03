<?php

namespace App\Http\Controllers;

use App\Models\Question;
use App\Models\Survey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SurveyController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:api');
    }

    public function createSurvey(Request $request)
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
            $form = Survey::create($data);

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

    public function mySurveys(Request $request)
    {
        $userId = Auth::id();

        $status = $request->query('status');

        $query = Survey::with(['questions.options', 'questions.attachments'])
            ->where('user_id', $userId);

        if ($status && in_array($status, ['publico', 'privado', 'borrador', 'desabilitado'])) {
            $query->where('status', $status);
        }

        $surveys = $query->orderBy('created_at', 'desc')->get();

        return response()->json([
            'data' => $surveys,
        ]);
    }

    public function submitResponse(Request $request, $code)
    {
        $form = \App\Models\Form::where('code', $code)->firstOrFail();

    // Log basic request data (note: files won't appear in $request->all())
    \Illuminate\Support\Facades\Log::info('Submit Response Request Data (non-files):', $request->except('file'));
    \Illuminate\Support\Facades\Log::info('Submit Response - Files keys: ' . implode(', ', array_keys($request->files->all())));

        // Validar respuestas
        // Nota: La validación detallada ya se hace en el frontend, aquí aseguramos estructura básica
        $data = $request->validate([
            'answers' => 'required|array',
            'answers.*.question_id' => 'required|exists:questions,id',
            'answers.*.value' => 'nullable', // Puede ser string, array (checkbox), o null
        ]);

        DB::beginTransaction();
        try {
            // Crear Submission
            $submission = \App\Models\Submission::create([
                'form_id' => $form->id,
                'user_id' => Auth::id(), // Puede ser null si es invitado
            ]);

            foreach ($data['answers'] as $key => $ans) {
                $question = \App\Models\Question::find($ans['question_id']);
                
                // Manejar diferentes tipos de respuesta
                $answerText = null;
                $options = [];

                if ($request->hasFile("answers.{$key}.value")) {
                    // Archivo
                    \Illuminate\Support\Facades\Log::info("Detected file for answers.{$key}.value");
                    $supabase = new \App\Services\SupabaseService();
                    $file = $request->file("answers.{$key}.value");
                    // Log file meta
                    if ($file) {
                        \Illuminate\Support\Facades\Log::info("File meta - originalName: {$file->getClientOriginalName()}, mime: {$file->getClientMimeType()}, size: {$file->getSize()}");
                    } else {
                        \Illuminate\Support\Facades\Log::warning("hasFile returned true but file() returned null for answers.{$key}.value");
                    }

                    $url = $supabase->uploadFile($file, 'responses');
                    if ($url) {
                        $answerText = $url;
                    } else {
                        \Illuminate\Support\Facades\Log::error("Supabase upload returned null for question {$question->id}");
                        throw new \Exception("Error al subir archivo");
                    }
                } elseif (is_array($ans['value'])) {
                    // Checkbox o similar
                    $answerText = json_encode($ans['value']); // Guardamos como JSON por si acaso
                } else {
                    $answerText = $ans['value'];
                }

                $response = \App\Models\Response::create([
                    'form_id' => $form->id,
                    'question_id' => $question->id,
                    'user_id' => Auth::id(),
                    'submission_id' => $submission->id,
                    'answer_text' => $answerText,
                ]);
            }

            DB::commit();
            return response()->json(['message' => 'Respuestas guardadas correctamente']);

        } catch (\Exception $e) {
            DB::rollBack();
            \Illuminate\Support\Facades\Log::error('Error in submitResponse: ' . $e->getMessage());
            \Illuminate\Support\Facades\Log::error($e->getTraceAsString());
            return response()->json(['error' => 'Error al guardar respuestas', 'details' => $e->getMessage()], 500);
        }
    }

    public function getResults(Request $request, $formId)
    {
        $userId = Auth::id();
        $form = \App\Models\Form::where('id', $formId)->where('user_id', $userId)->firstOrFail();

        $query = \App\Models\Submission::with(['user', 'responses.question.options'])
            ->where('form_id', $formId);

        // Búsqueda por nombre de usuario (si aplica)
        if ($request->has('search')) {
            $search = $request->search;
            $query->whereHas('user', function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%");
            });
        }

        // Ordenamiento
        $sort = $request->query('sort', 'created_at');
        $direction = $request->query('direction', 'desc');

        if ($sort === 'user') {
            $query->join('users', 'submissions.user_id', '=', 'users.id')
                  ->select('submissions.*') // Avoid overwriting id
                  ->orderBy('users.name', $direction);
        } else {
            $query->orderBy($sort, $direction);
        }

        $submissions = $query->paginate(10);

        if ($form->type === 'quiz') {
            foreach ($submissions as $sub) {
                $totalScore = 0;
                $maxScore = 0;

                // Calculate Max Score for the Form
                $formQuestions = $form->questions()->with('options')->get();
                foreach ($formQuestions as $q) {
                     if (in_array($q->type, ['text-input', 'date'])) {
                        $maxScore += $q->total_score ?? 0;
                     } else {
                        $qMax = $q->options->where('is_correct', true)->sum('score');
                        if ($qMax == 0) $qMax = $q->total_score ?? 0;
                        $maxScore += $qMax;
                     }
                }

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
                        // Decode if JSON (checkbox)
                        $selectedTexts = json_decode($userAnswer, true);
                        if (!is_array($selectedTexts)) $selectedTexts = [$userAnswer];

                        // Get all correct options for this question
                        $correctOptions = $q->options->where('is_correct', true);
                        $correctTexts = $correctOptions->pluck('text')->toArray();

                        // Check strict grading (required = 1)
                        if ($q->required) {
                            $allCorrectSelected = !array_diff($correctTexts, $selectedTexts) && !array_diff($selectedTexts, $correctTexts);
                            
                            if ($allCorrectSelected) {
                                $points = $correctOptions->sum('score');
                            } else {
                                $points = 0;
                            }
                        } else {
                            // Loose: Sum points for each correct option selected
                            $selectedOptions = $q->options->whereIn('text', $selectedTexts);
                            foreach ($selectedOptions as $opt) {
                                if ($opt->is_correct) {
                                    $points += $opt->score ?? 0;
                                }
                            }
                        }
                    } elseif ($q->type === 'scale') {
                         // Scale logic
                         if ($userAnswer == $q->answer) { 
                             $points = $q->total_score ?? 0;
                         }
                    }

                    $totalScore += $points;
                    
                    // Attach score to response for frontend
                    $resp->score = $points;
                    $resp->is_correct = $points > 0;
                }
                
                $sub->score = $totalScore;
                $sub->max_score = $maxScore;
            }
        }

        return response()->json($submissions);
    }

    public function deleteSubmission($id)
    {
        $userId = Auth::id();
        $submission = \App\Models\Submission::where('id', $id)->firstOrFail();
        
        // Verificar que el usuario sea dueño del form
        if ($submission->form->user_id !== $userId) {
            return response()->json(['error' => 'No autorizado'], 403);
        }

        $submission->delete();
        return response()->json(['message' => 'Respuesta eliminada correctamente']);
    }

    public function show($id)
    {
        $survey = Survey::with(['questions.options', 'questions.attachments'])
            ->where('user_id', Auth::id())
            ->where('id', $id)
            ->first();

        if (! $survey) {
            return response()->json(['error' => 'Encuesta no encontrada'], 404);
        }

        return response()->json($survey);
    }

    public function updateSurvey(Request $request, $formId)
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
            $form = Survey::where('id', $formId)
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
}
