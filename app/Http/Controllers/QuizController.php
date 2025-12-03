<?php

namespace App\Http\Controllers;

use App\Models\Question;
use App\Models\Quiz;
use App\Models\Form;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str; // <-- importante

class QuizController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:api');
    }

    public function createQuiz(Request $request)
    {
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'status' => 'nullable|in:publico,privado,borrador,activo,inactivo', // Expanded to include publico/borrador
            'type' => 'required|in:quiz', // Enforce type quiz
            'questions' => 'required|array|min:1',
        ]);

        $data['code'] = strtoupper(Str::random(8));
        $data['user_id'] = Auth::id();
        $data['status'] = $data['status'] ?? 'borrador'; // Default to borrador if null

        DB::beginTransaction();
        try {
            $quiz = Quiz::create($data);

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
                    'scale_min' => $q['min'] ?? null,
                    'scale_max' => $q['max'] ?? null,
                    'scale_label_min' => $q['labels']['min'] ?? null,
                    'scale_label_max' => $q['labels']['max'] ?? null,
                    'total_score' => $q['total_score'] ?? (
                        isset($q['options']) 
                        ? collect($q['options'])->where('is_correct', true)->sum('score') 
                        : null
                    ),
                    'answer' => is_array($q['answer'] ?? null) ? json_encode($q['answer']) : ($q['answer'] ?? null),
                ]);
                $quiz->questions()->save($question);

                // Opciones
                if (isset($q['options'])) {
                    foreach ($q['options'] as $opt) {
                        if (is_array($opt)) {
                            $question->options()->create([
                                'text' => $opt['text'],
                                'is_correct' => $opt['is_correct'] ?? false,
                                'score' => $opt['score'] ?? null,
                            ]);
                        } else {
                            $question->options()->create([
                                'text' => $opt,
                            ]);
                        }
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
                'message' => 'Quiz creado correctamente',
                'form_code' => $quiz->code,
                'form_id' => $quiz->id,
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'error' => 'Error al crear el quiz',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    public function myQuizzes(Request $request)
    {
        $userId = Auth::id();

        // Opcional: filtrar por estado si se pasa en query ?status=activo
        $status = $request->query('status'); // puede ser 'activo', 'inactivo', 'privado'

        $query = Quiz::with(['questions.options', 'questions.attachments'])
            ->where('user_id', $userId);

        if ($status && in_array($status, ['activo', 'inactivo', 'privado'])) {
            $query->where('status', $status);
        }

        $quizzes = $query->orderBy('created_at', 'desc')->get();

        return response()->json([
            'data' => $quizzes,
        ]);
    }
    public function updateQuiz(Request $request, $formId)
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
            // Buscar quiz del usuario
            $form = Form::where('id', $formId)
                ->where('user_id', $userId)
                ->firstOrFail();

            // Actualizar quiz
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

                    'scale_min' => $q['scale_min'] ?? null,
                    'scale_max' => $q['scale_max'] ?? null,
                    'scale_label_min' => $q['scale_label_min'] ?? null,
                    'scale_label_max' => $q['scale_label_max'] ?? null,
                    'total_score' => $q['total_score'] ?? (
                        isset($q['options']) 
                        ? collect($q['options'])->where('is_correct', true)->sum('score') 
                        : null
                    ),
                    'answer' => is_array($q['answer'] ?? null) ? json_encode($q['answer']) : ($q['answer'] ?? null),
                ]);

                // Opciones (si aplica)
                if (isset($q['options'])) {
                    foreach ($q['options'] as $opt) {
                        $question->options()->create([
                            'text' => is_array($opt) ? $opt['text'] : $opt,
                            'is_correct' => is_array($opt) ? ($opt['is_correct'] ?? false) : false,
                            'score' => is_array($opt) ? ($opt['score'] ?? null) : null,
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
                'message' => 'Quiz actualizado correctamente',
                'form_id' => $formId,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'error' => 'Error al actualizar el Quiz',
                'details' => $e->getMessage(),
            ], 500);
        }
    }
    public function submitQuiz(Request $request, $code)
    {
        $user = Auth::user();
        $form = Form::where('code', $code)->with(['questions.options'])->firstOrFail();

        if ($form->type !== 'quiz') {
            return response()->json(['error' => 'Este formulario no es un quiz'], 400);
        }

        // Check if already submitted
        $existingSubmission = \App\Models\Submission::where('form_id', $form->id)
            ->where('user_id', $user->id)
            ->first();

        if ($existingSubmission) {
             return response()->json(['error' => 'Ya has respondido este quiz'], 403);
        }

        $answers = $request->input('answers'); // Array of { question_id, value }
        $totalScore = 0;
        $maxScore = 0;

        DB::beginTransaction();
        try {
            $submission = \App\Models\Submission::create([
                'form_id' => $form->id,
                'user_id' => $user->id,
            ]);

            foreach ($form->questions as $question) {
                // For text/date, max score is the question's total_score
                // For others, max score is sum of correct options' scores (or just total_score if set, but let's stick to user request: total_score for question)
                // Actually, user said: "score deberia ser atributo de cada opcion... pero lo puse de manera general en la tabla questions... renombrado el atributo score a total_score... a la tabla options he agregado score"
                // So:
                // Text/Date: use question.total_score
                // Choice: use sum of option.score for selected correct options? Or just option.score?
                // Let's assume for choice questions, the max score is the sum of all positive scores of correct options, or the question.total_score if provided?
                // User said: "puede que 2 opciones sean correctas y den un puntae por cada acierto"
                
                // Let's calculate max possible score for this question first
                $questionMaxScore = 0;
                if (in_array($question->type, ['text-input', 'date'])) {
                    $questionMaxScore = $question->total_score ?? 0;
                } else {
                    // For choice questions, max score is sum of scores of all correct options
                    $questionMaxScore = $question->options()->where('is_correct', true)->sum('score');
                    // Fallback: if no option scores, maybe use question total_score?
                    if ($questionMaxScore == 0) {
                        $questionMaxScore = $question->total_score ?? 0;
                    }
                }
                
                $maxScore += $questionMaxScore;

                $userAnswer = null;
                
                // Find user answer for this question
                if ($answers) {
                    foreach ($answers as $ans) {
                        if ($ans['question_id'] == $question->id) {
                            $userAnswer = $ans['value'];
                            break;
                        }
                    }
                }

                $points = 0;

                // Save Response
                $response = \App\Models\Response::create([
                    'form_id' => $form->id,
                    'question_id' => $question->id,
                    'user_id' => $user->id,
                    'submission_id' => $submission->id,
                    'answer_text' => is_array($userAnswer) ? json_encode($userAnswer) : $userAnswer,
                ]);

                // Logic for scoring
                if ($question->type === 'text-input' || $question->type === 'date') {
                     // Decode stored answer (it might be a JSON string of options or a simple string)
                     $validAnswers = json_decode($question->answer, true);
                     
                     // If it's not a JSON array, treat it as a single string value
                     if (!is_array($validAnswers)) {
                         $validAnswers = [$question->answer];
                     }
                     
                     // Normalize user answer and valid answers for comparison (trim, lowercase if text)
                     if ($userAnswer) {
                         $normalizedUserAnswer = trim(strtolower($userAnswer));
                         $normalizedValidAnswers = array_map(function($a) { return trim(strtolower($a)); }, $validAnswers);
                         
                         if (in_array($normalizedUserAnswer, $normalizedValidAnswers)) {
                             $points = $question->total_score ?? 0;
                         }
                     }

                } elseif (in_array($question->type, ['multiple-choice', 'checkbox', 'radio-button', 'dropdown'])) {
                    
                    if (is_array($userAnswer)) {
                         // Multiple choice / Checkbox
                         $selectedTexts = $userAnswer;
                         // Get selected options that are correct and sum their scores
                         // We need to match by text
                         $selectedOptions = $question->options()->whereIn('text', $selectedTexts)->get();
                         
                         foreach ($selectedOptions as $opt) {
                             if ($opt->is_correct) {
                                 $points += $opt->score ?? 0;
                             }
                         }
                         
                    } else {
                        // Single choice
                        // Find the selected option
                        $selectedOption = $question->options()->where('text', $userAnswer)->first();
                        if ($selectedOption && $selectedOption->is_correct) {
                            $points += $selectedOption->score ?? 0;
                        }
                    }
                }
                elseif ($question->type === 'scale') {

    $correctValue = $question->answer;
    $correctValue = is_numeric($correctValue) ? (int)$correctValue : null;

    if ($correctValue !== null && (int)$userAnswer === $correctValue) {
        $points = $question->total_score ?? 0;
    }
}

                $totalScore += $points;
            }
            
            DB::commit();
            
            return response()->json([
                'message' => 'Quiz enviado',
                'score' => $totalScore,
                'max_score' => $maxScore,
                'percentage' => $maxScore > 0 ? round(($totalScore / $maxScore) * 100, 2) : 0
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function getResults(Request $request, $formId)
    {
        $userId = Auth::id();
        // Ensure it's a quiz owned by the user
        $form = \App\Models\Form::where('id', $formId)
            ->where('user_id', $userId)
            ->where('type', 'quiz')
            ->firstOrFail();

        $query = \App\Models\Submission::with(['user', 'responses.question.options'])
            ->where('form_id', $formId);

        // Search by user name
        if ($request->has('search')) {
            $search = $request->search;
            $query->whereHas('user', function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%");
            });
        }

        // Sorting
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
                        $correctValue = $q->answer;
                        $correctValue = is_numeric($correctValue) ? (int)$correctValue : null;

                        if ($correctValue !== null && (int)$userAnswer === $correctValue) {
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

        return response()->json($submissions);
    }
}
