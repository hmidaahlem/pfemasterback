<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use App\Models\User;

class ChatbotController extends Controller
{
    /**
     * Ask the AI chatbot — supports function calling for all three AI providers.
     */
    public function ask(Request $request): JsonResponse
    {
        $request->validate([
            'product_id' => 'nullable|exists:products,id',
            'message'    => 'required|string|max:1000',
        ]);

        $productId = $request->input('product_id');
        $message   = $request->input('message');

        if (!$productId) {
            $cleanMessage = mb_strtolower($message);
            // Sort products by name length DESC so longer names match first
            // (e.g., 'soupe lentille' matches before 'soupe')
            $matchingProduct = Product::where('is_active', true)
                ->where('approval_status', 'approved')
                ->get()
                ->sortByDesc(fn($p) => strlen($p->name))
                ->first(function ($p) use ($cleanMessage) {
                    return str_contains($cleanMessage, mb_strtolower($p->name));
                });
            if ($matchingProduct) {
                $productId = $matchingProduct->id;
            }
        }

        /** @var User|null $user */
        $user     = Auth::user();
        $userRole = $user && $user->role ? $user->role->name : 'GUEST';
        $userName = $user ? "{$user->first_name} {$user->last_name}" : 'Utilisateur';

        // ─── 1. CAISSIER RESTRICTIONS ──────────────────────────────────────────
        if ($userRole === 'CAISSIER') {
            if ($this->isForbiddenForCashier($message)) {
                return response()->json([
                    'success'  => true,
                    'response' => "Acces limite : En tant que Caissier, vous pouvez uniquement poser des questions sur les allergenes, les ingredients ou la compatibilite sante de nos produits pour repondre aux clients. Les questions administratives, financieres ou logistiques (stocks, commandes, plannings, utilisateurs) ne sont pas autorisees pour ce role.",
                    'source'   => 'cashier_restriction_engine'
                ]);
            }
        }

        // ─── 2. ROLE-BASED SYSTEM PROMPT ──────────────────────────────────────
        $roleDescriptions = [
            'SUPER_ADMIN'        => "Tu es le copilote global de AeroServe. Tu as acces a la gestion globale des utilisateurs, des points de vente et aux KPIs financiers. Tu peux repondre aux questions d'administration generale.",
            'RESPONSABLE_FB'     => "Tu es le responsable F&B de AeroServe. Tu as acces a la planification des shifts des caissiers, a la gestion operationnelle des points de vente, et a la commande d'articles. Tu ne dois pas divulguer d'informations confidentielles sur d'autres roles.",
            'CHEF_CUISINE'       => "Tu es le Chef de Cuisine. Tu t'occupes des recettes, des produits alimentaires (Food), des menus du personnel et des commandes de matieres premieres. Ne reponds jamais aux questions sur les statistiques de vente des points de vente, la comptabilite ou les salaires.",
            'CHEF_MAGASIN'       => "Tu es le Chef de Magasinier. Tu t'occupes de la gestion FIFO du stock en reserve, des lots perimes, et de la validation des commandes commerciales. Tu n'as pas acces aux recettes secretes de cuisine ou aux donnees financieres.",
            'RESPONSABLE_ACHAT'  => "Tu es le responsable Achat. Tu valides les produits soumis par le magasinier, configures les prix d'achat, geres les categories, et analyses les previsions d'approvisionnement IA.",
            'RESPONSABLE_HYGIENE'=> "Tu es le responsable Hygiene. Tu rediges les audits de securite alimentaire, declares les allergenes et signales les non-conformites.",
            'CAISSIER'           => "Tu es l'assistant nutritionnel du Caissier en contact avec les clients. Tu reponds exclusivement aux questions des clients sur la composition, les allergenes et la conformite sante du produit en te basant sur les fiches d'hygiene.",
        ];

        $roleInstruction = $roleDescriptions[$userRole] ?? "Tu es l'assistant de l'application AeroServe.";
        $userContext     = $user ? $this->getUserContextText($user) : "Aucun contexte utilisateur.";
        
        $productContext      = "";
        $hygieneReportsContext = "";

        if ($productId) {
            $product = Product::with(['stock', 'hygieneReports', 'ingredients'])->find($productId);

            if (!$product) {
                return response()->json(['success' => false, 'message' => 'Produit non trouve.'], 404);
            }

            $isHealthQuestion = false;
            $healthKeywords = [
                'allergen', 'allerg', 'gluten', 'lactose', 'arachid', 'diabét', 'diabet',
                'sant', 'health', 'gesund', 'salud', 'salute',
                'sécurit', 'securit', 'sicher', 'segur', 'sicur',
                'ingréd', 'ingred', 'zutat',
                'compos', 'inhalt',
                'calor', 'nutri', 'conforme', 'hygièn', 'hygien', 'malad',
                'حساسية', 'جلوتين', 'لاكتوز', 'مكونات', 'صحة', 'مرض',
                'سكري', 'مريض', 'أمان', 'سليم', 'تسمم', 'انتهاء', 'تاريخ',
                'مكسرات', 'صويا', 'سمك', 'بيض', 'قمح', 'حليب',
            ];
            $messageLower = mb_strtolower($message);
            foreach ($healthKeywords as $kw) {
                if (str_contains($messageLower, $kw)) {
                    $isHealthQuestion = true;
                    break;
                }
            }

            // 1. NON-CONFORMITY GUARDRAIL
            $hasNonConformeReport = $product->hygieneReports->contains('status', 'non_conforme');
            if ($hasNonConformeReport && ($userRole === 'CAISSIER' || $userRole === 'GUEST' || $isHealthQuestion)) {
                $lang = $this->detectMessageLanguage($message);
                $nonConformeWarnings = [
                    'ar' => "عذراً، هذا المنتج غير مطابق لمعايير السلامة الصحية الرسمية (NON CONFORME). لدواعي السلامة، لا يمكن تقديم أي معلومات أو نصائح صحية لهذا المنتج.",
                    'fr' => "Désolé, ce produit est marqué non conforme (NON CONFORME) par le responsable Hygiène. Pour des raisons de sécurité alimentaire, aucune information (ingrédients, DLC, allergènes) ne peut être divulguée pour ce produit.",
                    'en' => "Sorry, this product is marked as non-conforming (NON CONFORME) by the hygiene officer. For food safety reasons, no information (ingredients, expiration date, allergens) can be provided for this product.",
                    'es' => "Lo sentimos, este producto está marcado como no conforme (NON CONFORME) por el responsable de higiene. Por razones de seguridad alimentaria, no se puede proporcionar ninguna información sobre este producto.",
                    'it' => "Spiacenti, questo produit est contrassegnato come non conforme (NON CONFORME) dal responsabile dell'igiene. Per motivi di sicurezza alimentaire, non è possibile fornire alcuna informazione su questo produit.",
                    'de' => "Entschuldigung, dieses Produkt wurde vom Hygienebeauftragten als nicht konform (NON CONFORME) eingestuft. Aus Gründen der Lebensmittelsicherheit können keine Informationen zu diesem Produkt bereitgestellt werden."
                ];
                $responseMsg = $nonConformeWarnings[$lang] ?? $nonConformeWarnings['en'];

                return response()->json([
                    'success'  => true,
                    'response' => $responseMsg,
                    'source'   => 'hygiene_non_conforme_guardrail'
                ]);
            }

            // 2. RECIPE INGREDIENTS LOADING
            $ingredientsList = "";
            if ($product->ingredients->isNotEmpty()) {
                $ingredientsList = $product->ingredients->map(fn($i) => "- " . $i->name . " (" . $i->pivot->quantity . " " . ($i->pivot->unit ?? 'piece') . ")")->implode("\n");
            } else {
                $ingredientsList = trim($product->description ?? '');
            }

            $hasHygiene = $product->hygieneReports->isNotEmpty();
            $hasIngredients = !empty($ingredientsList) && strlen($ingredientsList) > 2;

            // 3. COMPLIANCE/SAFETY QUESTIONS GUARDRAIL (Only block if they ask about compliance/safety AND there are no reports)
            $isComplianceQuestion = false;
            $complianceKeywords = [
                'conforme', 'hygièn', 'hygien', 'sécurit', 'securit', 'sicher', 'segur', 'sicur',
                'أمان', 'سليم', 'تسمم', 'مطابق', 'صحة', 'سلامة'
            ];
            foreach ($complianceKeywords as $kw) {
                if (str_contains($messageLower, $kw)) {
                    $isComplianceQuestion = true;
                    break;
                }
            }

            if (($userRole === 'CAISSIER' || $userRole === 'GUEST' || $isHealthQuestion) && $isComplianceQuestion) {
                if (!$hasHygiene) {
                    $lang = $this->detectMessageLanguage($message);
                    $warnings = [
                        'ar' => "عذراً، لم يتم تسجيل أي تقرير صحي رسمي أو شهادة مطابقة للمنتج \"" . $product->name . "\" من قبل مسؤول النظافة والسلامة الصحية (RESPONSABLE_HYGIENE). لدواعي السلامة، لا يمكن تأكيد مطابقة هذا المنتج.",
                        'fr' => "Désolé, aucun rapport officiel d'hygiène ou certificat de conformité n'a été enregistré pour le produit \"" . $product->name . "\" par le responsable Hygiène (RESPONSABLE_HYGIENE). Par sécurité, la conformité de ce produit ne peut être confirmée.",
                        'en' => "Sorry, no official hygiene report or conformity certificate has been recorded for the product \"" . $product->name . "\" by the hygiene officer (RESPONSABLE_HYGIENE). For safety reasons, the conformity of this product cannot be confirmed.",
                        'es' => "Lo sentimos, el responsable de higiene (RESPONSABLE_HYGIENE) no ha registrado ningún informe oficial de higiene ni certificado de conformidad para el producto \"" . $product->name . "\". Por seguridad, no se puede confirmar la conformidad de este producto.",
                        'it' => "Spiacenti, non è stato registrato alcun rapporto ufficiale di igiene o certificato di conformità per il prodotto \"" . $product->name . "\" da parte del responsabile dell'igiene (RESPONSABLE_HYGIENE). Per sicurezza, la conformità di questo produit non può essere confermata.",
                        'de' => "Entschuldigung, für das Produkt \"" . $product->name . "\" wurde vom Hygienebeauftragten (RESPONSABLE_HYGIENE) kein offizieller Hygienebericht oder Konformitätszertifikat erfasst. Aus Sicherheitsgründen kann die Konformität dieses Produkts nicht bestätigt werden."
                    ];
                    $responseMsg = $warnings[$lang] ?? $warnings['en'];

                    return response()->json([
                        'success'  => true,
                        'response' => $responseMsg,
                        'source'   => 'hygiene_guardrail_engine'
                    ]);
                }
            }

            $ingredients   = $ingredientsList ?: 'Aucun ingredient specifie.';
            $allergensList = is_array($product->allergens) ? implode(', ', $product->allergens) : ($product->allergens ?? 'Aucun.');
            $productContext = "Voici les details du produit alimentaire actuel :\n" .
                              "Produit: {$product->name}\n" .
                              "Type: {$product->type}\n" .
                              "Description/Ingredients: \n{$ingredients}\n" .
                              "Allergenes declares: {$allergensList}\n" .
                              "Prix: {$product->price} TND\n";

            if ($product->expiration_date) {
                $productContext .= "Date d'expiration: {$product->expiration_date}\n";
            }

            if ($product->hygieneReports->isNotEmpty()) {
                $hygieneReportsContext = "\nDeclarations et audits du responsable Hygiene pour ce produit :\n";
                foreach ($product->hygieneReports as $report) {
                    $statusStr   = $report->status;
                    $allergensStr = $report->allergens_verified ? "Allergenes verifies et valides" : "Allergenes non verifies";
                    $expStr      = $report->expiration_verified ? "Date d'expiration verifiee" : "Date d'expiration non verifiee";
                    $hygieneReportsContext .= "- Statut: {$statusStr} | {$allergensStr} | {$expStr}\n";
                    if ($report->remarks) {
                        $hygieneReportsContext .= "  Remarques de l'officier d'hygiene (RESPONSABLE_HYGIENE): \"{$report->remarks}\"\n";
                    }
                }
            } else {
                $hygieneReportsContext = "\nDeclarations du responsable Hygiene: Aucune remarque d'hygiene specifique n'a encore ete enregistree pour ce produit.\n";
            }

            // 4. RADICAL PRE-GROQ GUARDRAIL
            // When a health/ingredient/allergen query is detected AND the product has NO data in DB,
            // bypass Groq entirely and return a deterministic server-side response.
            // This prevents Groq from hallucinating invented ingredient/allergen data.
            if ($isHealthQuestion) {
                $hasAnyData = $hasIngredients || $hasHygiene ||
                              ($product->allergens && !empty($product->allergens)) ||
                              $product->expiration_date;

                if (!$hasAnyData) {
                    $lang = $this->detectMessageLanguage($message);
                    $emptyDataResponses = [
                        'ar' => "لم يتم تسجيل أي مكونات أو بيانات صحية رسمية للمنتج \"" . $product->name . "\" في النظام حتى الآن. يرجى التواصل مع Chef Cuisine لتسجيل مكونات الوصفة، ومع RESPONSABLE_HYGIENE لتسجيل التقارير الصحية.",
                        'fr' => "Aucune donnée (ingrédients, allergènes, DLC, rapport d'hygiène) n'a encore été enregistrée pour le produit \"" . $product->name . "\" dans le système. Veuillez contacter le Chef Cuisine pour enregistrer la recette, et le RESPONSABLE_HYGIENE pour les rapports sanitaires.",
                        'en' => "No data (ingredients, allergens, DLC, hygiene report) has been recorded yet for the product \"" . $product->name . "\" in the system. Please contact the Chef Cuisine to register the recipe, and the RESPONSABLE_HYGIENE for health reports.",
                        'es' => "No se han registrado datos (ingredientes, alérgenos, DLC, informe de higiene) para el producto \"" . $product->name . "\" en el sistema. Contacte al Chef Cuisine para registrar la receta y al RESPONSABLE_HYGIENE para los informes sanitarios.",
                        'it' => "Nessun dato (ingredienti, allergeni, DLC, rapporto igienico) è ancora stato registrato per il prodotto \"" . $product->name . "\" nel sistema. Contattare il Chef Cuisine per registrare la ricetta e il RESPONSABLE_HYGIENE per i rapporti sanitari.",
                        'de' => "Für das Produkt \"" . $product->name . "\" wurden noch keine Daten (Zutaten, Allergene, MHD, Hygienebericht) im System erfasst. Bitte wenden Sie sich an den Chef Cuisine für die Rezeptregistrierung und den RESPONSABLE_HYGIENE für die Hygieneberichte.",
                    ];
                    $responseMsg = $emptyDataResponses[$lang] ?? $emptyDataResponses['fr'];
                    return response()->json([
                        'success'  => true,
                        'response' => $responseMsg,
                        'source'   => 'data_missing_guardrail'
                    ]);
                }

                // If data exists — use local NLP directly for health questions (no Groq needed)
                // Groq only adds hallucination risk for health-critical data
                $localResponse = $this->getLocalNlpResponse($product, $message);
                return response()->json([
                    'success'  => true,
                    'response' => $localResponse,
                    'source'   => 'local_nlp_engine'
                ]);
            }
        }

        if ($userRole === 'CAISSIER' || $userRole === 'GUEST') {
            $roleTitle = $userRole === 'GUEST'
                ? "Tu es l'assistant clientèle officiel d'AeroServe pour les visiteurs et voyageurs de l'aéroport."
                : "Tu es l'assistant nutritionnel du Caissier de AeroServe en contact direct avec les clients.";

            $systemRole = "<role>\n" .
                          $roleTitle . "\n" .
                          "</role>\n\n" .
                          "<context>\n" .
                          $productContext . "\n" .
                          $hygieneReportsContext . "\n" .
                          "</context>\n\n" .
                          "1. SÉCURITÉ ALIMENTAIRE STRICTE : Tu as L'INTERDICTION ABSOLUE d'utiliser tes propres connaissances pré-entraînées ou de faire des suppositions sur la composition, la santé, la sécurité, ou la présence d'allergènes dans le produit. Ne te fie qu'aux données écrites textuellement dans le <context> ci-dessus.\n" .
                          "2. MODE D'ÉCHEC (Pas de données) : Si le produit n'a aucun ingrédient listé dans le context, ou si la section 'Remarques du responsable Hygiene' est absente ou indique 'Aucune remarque', tu DOIS obligatoirement répondre dans la langue du client : 'Désolé, aucun rapport officiel d'hygiène ou de conformité n'a été enregistré pour ce produit. Par sécurité, je ne peux pas me prononcer.' Ne dis JAMAIS que le produit est conforme s'il n'y a pas de rapport.\n" .
                          "3. RÉPONSES AUX ALLERGÈNES : Si le client pose une question sur un allergène et que cet allergène n'est pas spécifié comme vérifié dans le <context> fourni, réponds que l'information n'est pas confirmée officiellement et conseille-lui de ne pas consommer le produit.\n" .
                          "4. TON ET STYLE : Réponds de manière concise, polie et professionnelle. N'utilise absolument aucun emoji dans tes réponses.\n" .
                          "5. RESTRICTION DE RÔLE : Ne réponds à aucune question administrative (stocks, commandes, etc.). Tu es uniquement là pour assister sur le produit actuel affiché.\n" .
                          "</directives_strictes>";
        } else {
            $systemRole = "Tu es l'assistant intelligent et le copilote de l'application de restauration AeroServe.\n" .
                          "L'utilisateur connecte s'appelle {$userName} avec le role: {$userRole}.\n" .
                          $userContext . "\n" .
                          ($productId ? "<context>\n" . $productContext . "\n" . $hygieneReportsContext . "\n" . "</context>\n" : "") .
                          "Directives strictes de securite :\n" .
                          "- Tu ne dois repondre a AUCUNE question qui ne concerne pas directement l'application AeroServe. Les questions hors-sujet doivent etre refusees poliment.\n" .
                          "- RÈGLE ANTI-HALLUCINATION ABSOLUE : Si l'utilisateur demande un rapport d'hygiene, une conformite, un stock, ou des ingredients d'une chose, et que cette information n'est pas EXPLICITEMENT fournie dans le <context> ou par un appel de fonction (tool), tu DOIS repondre : 'Desole, je ne dispose d'aucune donnee officielle ou rapport pour cet element dans le systeme.'\n" .
                          "- NE DIS JAMAIS qu'un element est 'conforme' ou 'sans danger' si tu n'as pas lu un rapport d'hygiene explicite affirmant cela.\n" .
                          "- TU NE DOIS JAMAIS inventer de donnees, de profils, d'e-mails, d'informations de stocks, ou de commandes.\n" .
                          "- Si tu parles de la composition ou des allergenes d'un produit, base-toi STRICTEMENT sur les informations du <context> et ne fais aucune supposition.\n" .
                          "- Reponds de maniere concise, polie et dans la meme langue que celle de la question.\n" .
                          "- N'utilise absolument aucun emoji dans tes reponses.\n" .
                          "- Tu as acces a des outils (tools) pour chercher dans la base de donnees. Utilise-les pour obtenir des donnees reelles au lieu de deviner.";
        }

        $groqKey   = config('services.groq.key');
        if ($groqKey && $groqKey !== 'null' && !empty($groqKey)) {
            try {
                $url   = "https://api.groq.com/openai/v1/chat/completions";
                $tools = $this->getToolDefinitions();

                $messages = [
                    ['role' => 'system', 'content' => $systemRole],
                    ['role' => 'user',   'content' => $message],
                ];

                $response = Http::withHeaders([
                    'Authorization' => "Bearer {$groqKey}",
                    'Content-Type'  => 'application/json',
                ])->post($url, [
                    'model'       => 'llama-3.3-70b-versatile',
                    'messages'    => $messages,
                    'tools'       => $tools,
                    'tool_choice' => 'auto',
                    'temperature' => 0.2,
                    'max_tokens'  => 800,
                ]);

                if ($response->successful()) {
                    $data   = $response->json();
                    $choice = $data['choices'][0] ?? null;

                    if ($choice && ($choice['finish_reason'] === 'tool_calls') && !empty($choice['message']['tool_calls'])) {
                        $messages[] = $choice['message'];

                        foreach ($choice['message']['tool_calls'] as $toolCall) {
                            $toolName   = $toolCall['function']['name'];
                            $toolArgs   = json_decode($toolCall['function']['arguments'], true) ?? [];
                            $toolResult = $this->executeToolCall($toolName, $toolArgs);

                            $messages[] = [
                                'role'         => 'tool',
                                'tool_call_id' => $toolCall['id'],
                                'content'      => $toolResult,
                            ];
                        }

                        $finalResp = Http::withHeaders([
                            'Authorization' => "Bearer {$groqKey}",
                            'Content-Type'  => 'application/json',
                        ])->post($url, [
                            'model'       => 'llama-3.3-70b-versatile',
                            'messages'    => $messages,
                            'temperature' => 0.1,
                            'max_tokens'  => 800,
                        ]);

                        if ($finalResp->successful()) {
                            $aiResponse = $finalResp->json()['choices'][0]['message']['content'] ?? null;
                            if ($aiResponse) {
                                return response()->json([
                                    'success'  => true,
                                    'response' => trim($aiResponse),
                                    'source'   => 'groq_function_calling',
                                ]);
                            }
                        }
                    } elseif ($choice) {
                        $aiResponse = $choice['message']['content'] ?? null;
                        if ($aiResponse) {
                            return response()->json([
                                'success'  => true,
                                'response' => trim($aiResponse),
                                'source'   => 'groq_ai',
                            ]);
                        }
                    }
                }

                Log::warning('Groq API call failed.', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);

            } catch (\Exception $e) {
                Log::error('Error calling Groq API: ' . $e->getMessage());
            }
        }


        // ─── 6. LOCAL FALLBACK — NLP Engine ───────────────────────────────────
        if ($productId && isset($product)) {
            $localResponse = $this->getLocalNlpResponse($product, $message);
        } else {
            // Off-topic check before generating a local response
            if (!$this->isOnTopicMessage($message)) {
                $localResponse = "Je suis desole, je suis uniquement un assistant operationnel pour l'application AeroServe. Je ne reponds pas aux questions hors-sujet ou aux salutations generales. Posez-moi une question sur les produits, les stocks, les commandes, les plannings ou la gestion AeroServe.";
            } else {
                $localResponse = $this->getLocalGeneralResponse($message, $userRole);
            }
        }

        return response()->json([
            'success'  => true,
            'response' => $localResponse,
            'source'   => 'local_nlp_engine',
        ]);
    }


    private function getToolDefinitions(): array
    {
        return [
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'chercher_produits',
                    'description' => 'Recherche des produits dans le catalogue AeroServe par nom, type (food, commercial, matiere_premiere) ou mot-cle.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'query' => [
                                'type'        => 'string',
                                'description' => 'Terme de recherche : nom du produit, type ou mot-cle',
                            ],
                        ],
                        'required' => ['query'],
                    ],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'obtenir_details_produit',
                    'description' => "Obtenir les details complets d'un produit specifique : stock, rapports d'hygiene, ingredients, categorie, prix.",
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'product_id' => [
                                'type'        => 'integer',
                                'description' => 'ID unique du produit',
                            ],
                        ],
                        'required' => ['product_id'],
                    ],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'obtenir_tous_produits',
                    'description' => 'Obtenir le catalogue complet de tous les produits actifs et approuves.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => new \stdClass(),
                    ],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'obtenir_stocks',
                    'description' => 'Obtenir les niveaux de stock actuels de tous les produits ou filtrer par nom. Inclut quantite, unite et seuil critique.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'query' => [
                                'type'        => 'string',
                                'description' => 'Optionnel: nom du produit pour filtrer les stocks',
                            ],
                        ],
                    ],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'obtenir_commandes',
                    'description' => 'Obtenir les commandes internes recentes avec leur statut (EN_ATTENTE, EN_COURS, LIVREE, ANNULEE).',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'status' => [
                                'type'        => 'string',
                                'description' => 'Optionnel: filtrer par statut (EN_ATTENTE, EN_COURS, LIVREE, ANNULEE)',
                            ],
                        ],
                    ],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'obtenir_menu_semaine',
                    'description' => 'Obtenir le menu actuel ou le plus recent avec les plats prevus pour chaque jour de la semaine.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => new \stdClass(),
                    ],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'obtenir_rapports_hygiene',
                    'description' => "Obtenir les rapports d'hygiene et de conformite pour un produit specifique ou tous les rapports recents.",
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'product_id' => [
                                'type'        => 'integer',
                                'description' => 'Optionnel: ID du produit pour filtrer les rapports',
                            ],
                        ],
                    ],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'obtenir_statistiques',
                    'description' => 'Obtenir les KPIs et statistiques globales : nombre de produits, stock total, commandes en attente, alertes critiques.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => new \stdClass(),
                    ],
                ],
            ],
        ];
    }

    private function executeToolCall(string $toolName, array $args): string
    {
        switch ($toolName) {
            case 'chercher_produits':
                $query    = $args['query'] ?? '';
                $products = Product::with('category', 'stock')
                    ->where('is_active', true)
                    ->where(function ($q) use ($query) {
                        $q->where('name', 'like', "%{$query}%")
                          ->orWhere('type', 'like', "%{$query}%")
                          ->orWhere('description', 'like', "%{$query}%");
                    })
                    ->limit(10)
                    ->get();

                if ($products->isEmpty()) {
                    return json_encode(['message' => "Aucun produit trouve pour la recherche: '{$query}'"]);
                }

                return json_encode($products->map(fn($p) => [
                    'id'          => $p->id,
                    'nom'         => $p->name,
                    'type'        => $p->type,
                    'prix'        => $p->price,
                    'statut'      => $p->approval_status,
                    'stock'       => $p->stock ? $p->stock->quantity : 0,
                    'categorie'   => $p->category?->name,
                    'description' => $p->description,
                ])->values());

            case 'obtenir_details_produit':
                $productId = (int) ($args['product_id'] ?? 0);
                $product   = Product::with('stock', 'hygieneReports', 'category', 'ingredients')->find($productId);

                if (!$product) {
                    return json_encode(['erreur' => "Produit ID {$productId} non trouve dans la base de donnees."]);
                }

                return json_encode([
                    'id'                  => $product->id,
                    'nom'                 => $product->name,
                    'type'                => $product->type,
                    'description'         => $product->description,
                    'prix'                => $product->price,
                    'allergenes'          => $product->allergens,
                    'date_expiration'     => $product->expiration_date,
                    'statut_approbation'  => $product->approval_status,
                    'actif'               => $product->is_active,
                    'stock_disponible'    => $product->stock ? $product->stock->quantity . ' ' . ($product->stock->unit ?? '') : 'Aucun stock',
                    'categorie'           => $product->category?->name,
                    'nb_rapports_hygiene' => $product->hygieneReports?->count() ?? 0,
                    'ingredients'         => $product->ingredients?->map(fn($i) => [
                        'nom' => $i->name,
                        'quantite' => $i->pivot->quantity ?? 0,
                        'unite' => $i->pivot->unit ?? 'piece',
                    ])->toArray(),
                ]);

            case 'obtenir_tous_produits':
                $products = Product::with('category', 'stock')
                    ->where('is_active', true)
                    ->where('approval_status', 'approved')
                    ->get();

                return json_encode([
                    'nombre_total' => $products->count(),
                    'produits' => $products->map(fn($p) => [
                        'id'        => $p->id,
                        'nom'       => $p->name,
                        'type'      => $p->type,
                        'prix'      => $p->price,
                        'stock'     => $p->stock ? $p->stock->quantity : 0,
                        'categorie' => $p->category?->name,
                    ])->values(),
                ]);

            case 'obtenir_stocks':
                $query = $args['query'] ?? null;
                $stockQuery = \App\Models\Stock::with('product.category');

                if ($query) {
                    $stockQuery->whereHas('product', function ($q) use ($query) {
                        $q->where('name', 'like', "%{$query}%");
                    });
                }

                $stocks = $stockQuery->orderBy('quantity', 'asc')->limit(20)->get();

                if ($stocks->isEmpty()) {
                    return json_encode(['message' => $query
                        ? "Aucun stock trouve pour '{$query}'"
                        : 'Aucun stock enregistre dans le systeme.'
                    ]);
                }

                return json_encode([
                    'nombre_resultats' => $stocks->count(),
                    'stocks' => $stocks->map(fn($s) => [
                        'produit'     => $s->product?->name,
                        'type'        => $s->product?->type,
                        'quantite'    => $s->quantity,
                        'unite'       => $s->unit,
                        'seuil_alerte'=> $s->alert_threshold ?? 'Non defini',
                        'categorie'   => $s->product?->category?->name,
                    ])->values(),
                ]);

            case 'obtenir_commandes':
                $status = $args['status'] ?? null;
                $orderQuery = \App\Models\InternalOrder::with('items.product', 'creator')
                    ->orderBy('created_at', 'desc')
                    ->limit(10);

                if ($status) {
                    $orderQuery->where('status', $status);
                }

                $orders = $orderQuery->get();

                if ($orders->isEmpty()) {
                    return json_encode(['message' => $status
                        ? "Aucune commande avec le statut '{$status}'"
                        : 'Aucune commande interne enregistree.'
                    ]);
                }

                return json_encode([
                    'nombre_commandes' => $orders->count(),
                    'commandes' => $orders->map(fn($o) => [
                        'id'          => $o->id,
                        'type'        => $o->type,
                        'statut'      => $o->status,
                        'cree_par'    => $o->creator ? ($o->creator->first_name . ' ' . $o->creator->last_name) : 'N/A',
                        'date'        => $o->created_at?->format('Y-m-d H:i'),
                        'articles'    => $o->items->map(fn($i) => [
                            'produit'   => $i->product?->name,
                            'demande'   => $i->quantity_requested,
                            'livre'     => $i->quantity_fulfilled,
                        ])->toArray(),
                    ])->values(),
                ]);

            case 'obtenir_menu_semaine':
                $menu = \App\Models\Menu::with('items.product')
                    ->orderBy('start_date', 'desc')
                    ->first();

                if (!$menu) {
                    return json_encode(['message' => 'Aucun menu enregistre dans le systeme.']);
                }

                return json_encode([
                    'nom'        => $menu->name,
                    'debut'      => $menu->start_date,
                    'fin'        => $menu->end_date,
                    'statut'     => $menu->status,
                    'effectif'   => $menu->staff_count,
                    'plats'      => $menu->items->map(fn($i) => [
                        'jour'    => $i->day_of_week,
                        'repas'   => $i->meal_type,
                        'produit' => $i->product?->name,
                    ])->toArray(),
                ]);

            case 'obtenir_rapports_hygiene':
                $productId = $args['product_id'] ?? null;
                $reportQuery = \App\Models\HygieneReport::with('product', 'inspector')
                    ->orderBy('created_at', 'desc')
                    ->limit(10);

                if ($productId) {
                    $reportQuery->where('product_id', $productId);
                }

                $reports = $reportQuery->get();

                if ($reports->isEmpty()) {
                    return json_encode(['message' => $productId
                        ? "Aucun rapport d'hygiene pour le produit ID {$productId}."
                        : "Aucun rapport d'hygiene enregistre."
                    ]);
                }

                return json_encode($reports->map(fn($r) => [
                    'id'                   => $r->id,
                    'produit'              => $r->product?->name,
                    'statut'               => $r->status,
                    'allergenes_verifies'  => $r->allergens_verified ? 'Oui' : 'Non',
                    'expiration_verifiee'  => $r->expiration_verified ? 'Oui' : 'Non',
                    'remarques'            => $r->remarks ?? 'Aucune remarque',
                    'inspecteur'           => $r->inspector ? ($r->inspector->first_name . ' ' . $r->inspector->last_name) : 'N/A',
                    'date'                 => $r->created_at?->format('Y-m-d'),
                ])->values());

            case 'obtenir_statistiques':
                $totalProducts = Product::where('is_active', true)->count();
                $totalStock = \App\Models\Stock::sum('quantity');
                $pendingOrders = \App\Models\InternalOrder::where('status', 'EN_ATTENTE')->count();
                $criticalStocks = \App\Models\Stock::whereColumn('quantity', '<=', \Illuminate\Support\Facades\DB::raw('COALESCE(alert_threshold, 5)'))->count();
                $nonConformeReports = \App\Models\HygieneReport::where('status', 'non_conforme')->count();

                return json_encode([
                    'produits_actifs'        => $totalProducts,
                    'stock_total'            => round($totalStock, 2),
                    'commandes_en_attente'   => $pendingOrders,
                    'stocks_critiques'       => $criticalStocks,
                    'rapports_non_conformes' => $nonConformeReports,
                ]);

            default:
                return json_encode(['erreur' => "Outil '{$toolName}' inconnu."]);
        }
    }

    private function getLocalNlpResponse(Product $product, string $message): string
    {
        $messageLower = mb_strtolower($message);

        // Only respond to health-related questions; reject everything else
        $healthKeywords = [
            'allergen', 'allerg', 'allergi', 'gluten', 'lactose', 'arachide', 'diabète', 'diabete',
            'santé', 'sante', 'sécurité', 'securite', 'ingréd', 'compos',
            'calor', 'nutrit', 'conforme', 'hygiène', 'hygiene', 'malad',
            'dlc', 'expiration', 'perim', 'périm', 'expire',
            'حساسية', 'جلوتين', 'لاكتوز', 'مكونات', 'صحة', 'مرض',
            'سكري', 'مريض', 'أمان', 'سليم', 'تسمم', 'انتهاء', 'تاريخ',
            'مكسرات', 'صويا', 'سمك', 'بيض', 'قمح', 'حليب',
        ];

        $isHealthRelated = false;
        foreach ($healthKeywords as $kw) {
            if (str_contains($messageLower, $kw)) {
                $isHealthRelated = true;
                break;
            }
        }

        if (!$isHealthRelated) {
            return "Je suis desole, je ne reponds qu'aux questions sur la sante, les allergenes et la composition des produits. Pour toute question administrative ou logistique, utilisez les autres sections de l'application.";
        }

        // 1. Non-conformity check (Block everything if marked non_conforme)
        $hasNonConformeReport = $product->hygieneReports->contains('status', 'non_conforme');
        if ($hasNonConformeReport) {
            return "Désolé, ce produit est marqué non conforme (NON CONFORME) par le responsable Hygiène. Pour des raisons de sécurité alimentaire, aucune information (ingrédients, expiration, allergènes) ne peut être divulguée pour ce produit.";
        }

        // 2. Compliance check (Only block if they ask about compliance/safety AND there are no reports)
        $isComplianceQuestion = false;
        $complianceKeywords = [
            'conforme', 'hygièn', 'hygien', 'sécurit', 'securit', 'sicher', 'segur', 'sicur',
            'أمان', 'سليم', 'تسمم', 'مطابق', 'صحة', 'سلامة'
        ];
        foreach ($complianceKeywords as $kw) {
            if (str_contains($messageLower, $kw)) {
                $isComplianceQuestion = true;
                break;
            }
        }

        if ($isComplianceQuestion && $product->hygieneReports->isEmpty()) {
            return "Aucun rapport officiel d'hygiène ou certificat de conformité n'a été enregistré pour le produit \"" . $product->name . "\". Par sécurité, la conformité de ce produit ne peut être confirmée.";
        }

        // 3. Specific Query Parsers
        $isAllergenQuery = false;
        $allergenKeywords = ['allergen', 'allerg', 'حساسية', 'مكسرات', 'قمح', 'بيض', 'حليب', 'سمك', 'gluten', 'lactose', 'arachid'];
        foreach ($allergenKeywords as $kw) {
            if (str_contains($messageLower, $kw)) {
                $isAllergenQuery = true;
                break;
            }
        }

        $isIngredientQuery = false;
        $ingredientKeywords = ['ingréd', 'ingred', 'compos', 'مكونات', 'تركيب', 'وصفة'];
        foreach ($ingredientKeywords as $kw) {
            if (str_contains($messageLower, $kw)) {
                $isIngredientQuery = true;
                break;
            }
        }

        $isDlcQuery = false;
        $dlcKeywords = ['dlc', 'expiration', 'perim', 'périm', 'expire', 'تاريخ', 'انتهاء', 'صلاحية'];
        foreach ($dlcKeywords as $kw) {
            if (str_contains($messageLower, $kw)) {
                $isDlcQuery = true;
                break;
            }
        }

        $isConformQuery = false;
        $conformKeywords = ['conforme', 'hygien', 'hygièn', 'سلامة', 'صحة', 'مطابق', 'أمان'];
        foreach ($conformKeywords as $kw) {
            if (str_contains($messageLower, $kw)) {
                $isConformQuery = true;
                break;
            }
        }

        $allergensList = is_array($product->allergens) ? implode(', ', $product->allergens) : ($product->allergens ?? 'aucun');
        if (empty($allergensList)) {
            $allergensList = 'aucun';
        }

        // Ingredients Loading
        $ingredientsList = "";
        if ($product->ingredients && $product->ingredients->isNotEmpty()) {
            $ingredientsList = $product->ingredients->map(fn($i) => "- " . $i->name)->implode("\n");
        } else {
            $ingredientsList = trim($product->description ?? '');
        }

        $dlc = $product->expiration_date ?? 'non specifiee';

        // Compliance status string
        $statusStr = "Non evalue";
        $remarksStr = "";
        if ($product->hygieneReports->isNotEmpty()) {
            $lastReport = $product->hygieneReports->first();
            $statusStr = $lastReport->status === 'conforme' ? 'CONFORME' : ($lastReport->status === 'non_conforme' ? 'NON CONFORME' : 'En cours d\'inspection');
            $remarksStr = $lastReport->remarks ? " ({$lastReport->remarks})" : "";
        }

        // Response formatting based on question type
        if ($isAllergenQuery) {
            return "Les allergenes declares pour \"" . $product->name . "\" sont : " . $allergensList . ". (Statut : " . $statusStr . $remarksStr . ").";
        }

        if ($isIngredientQuery) {
            return "Composition / Ingredients de \"" . $product->name . "\" :\n" . ($ingredientsList ?: 'Aucun ingredient specifie.');
        }

        if ($isDlcQuery) {
            return "La date limite de consommation (DLC) pour \"" . $product->name . "\" est : " . $dlc . ". (Statut : " . $statusStr . ").";
        }

        if ($isConformQuery) {
            return "Statut de conformite hygiene pour \"" . $product->name . "\" : " . $statusStr . $remarksStr . ".";
        }

        // Fallback detailed summary
        return "Informations sanitaires pour \"" . $product->name . "\" :\n" .
               "- Composition/Ingredients :\n" . ($ingredientsList ?: 'Aucun') . "\n" .
               "- Date limite de consommation (DLC) : " . $dlc . "\n" .
               "- Allergenes declares : " . $allergensList . "\n" .
               "- Statut de conformite : " . $statusStr . $remarksStr;
    }

    /**
     * Check if the message contains words forbidden for a Caissier.
     */
    private function isForbiddenForCashier(string $message): bool
    {
        $msgLower = mb_strtolower($message);

        $forbiddenKeywords = [
            'commande', 'order', 'planning', 'shift', 'مناوبة', 'جدول', 'طلب',
            'stock', 'inventaire', 'magasin', 'achat', 'validation', 'user', 'utilisateur',
            'compte', 'mot de passe', 'statut', 'admin', 'fournisseur', 'coût', 'recette', 'salaire',
            'مبيعات', 'شفت', 'حساب', 'مستخدم', 'مدير', 'شراء', 'تعديل', 'حذف', 'إضافة', 'مخزن', 'لوجستيات', 'سعر', 'تكلفة',
        ];

        $allowedKeywords = [
            'allerg', 'allergi', 'gluten', 'lactose', 'arachid', 'œuf', 'oeuf', 'ingréd', 'ingred', 'compos', 'calor',
            'nutri', 'diabét', 'diabet', 'coeliaque', 'cœliaque', 'sensibl', 'malad', 'manger', 'sain',
            'lait', 'fromage', 'farine', 'blé', 'sucre', 'sel', 'gras', 'hygien', 'hygièn', 'sant', 'conform',
            'dlc', 'expiration', 'perim', 'périm', 'expire',
            'produit', 'info', 'dite', 'dire', 'tout', 'contien', 'comport', 'detail', 'détail', 'dis-moi', 'dites',
            'حساسية', 'جلوتين', 'لاكتوز', 'مكونات', 'صحة', 'مرض', 'سكري', 'ضغط', 'حليب', 'بيض', 'قمح', 'مريض', 'أكل', 'تناول',
            'صالح', 'تسمم', 'تاريخ', 'انتهاء', 'أمان', 'سليم', 'عنب', 'مكسرات', 'فول صويا', 'سمك',
        ];

        foreach ($forbiddenKeywords as $fk) {
            if (str_contains($msgLower, $fk)) {
                return true;
            }
        }

        $hasAllowed = false;
        foreach ($allowedKeywords as $ak) {
            if (str_contains($msgLower, $ak)) {
                $hasAllowed = true;
                break;
            }
        }

        return !$hasAllowed;
    }

    private function isOnTopicMessage(string $message): bool
    {
        $msgLower = mb_strtolower($message);

        $projectKeywords = [
            'produit', 'stock', 'commande', 'planning', 'menu', 'hygien',
            'allergen', 'allergi', 'ingred', 'recette', 'categori', 'fournisseur',
            'achat', 'magasin', 'cuisine', 'caissier', 'vente', 'pdv', 'livraison',
            'rapport', 'aeroserve', 'approvisionnement', 'inventaire', 'lot', 'fifo',
            'utilisateur', 'role', 'shift', 'horaire', 'validation', 'approbation',
            'commercial', 'matiere', 'notif', 'seuil', 'alerte', 'food',
            // Arabic
            'منتج', 'مخزن', 'طلب', 'تخطيط', 'قائمة', 'نظافة', 'حساسية', 'مكونات',
            'وصفة', 'فئة', 'مورد', 'شراء', 'مطبخ', 'صندوق', 'مستخدم', 'مناوبة',
            'مخزون', 'تقرير', 'فاتورة', 'اعتماد', 'تطبيق', 'نظام',
        ];

        $greetingBlacklist = [
            'bonjour', 'salut', 'bonsoir', 'coucou', 'bonne journee',
            'hello', 'hi,', ',hi', ' hi ', 'hey', 'good morning', 'good evening',
            'how are you', "what's up", 'whats up',
            'comment vas', 'comment allez', 'ca va', 'comment tu', 'tu vas bien',
            'quel temps', 'meteo', 'météo', 'blague', 'joke', 'raconte',
            'مرحبا', 'أهلا', 'صباح', 'مساء', 'كيف حالك', 'كيفك', 'السلام',
        ];

        // If greeting AND no project keyword → off-topic
        foreach ($greetingBlacklist as $greeting) {
            if (str_contains($msgLower, $greeting)) {
                foreach ($projectKeywords as $kw) {
                    if (str_contains($msgLower, $kw)) {
                        return true; // greeting with AeroServe context → allow
                    }
                }
                return false; // pure greeting → reject
            }
        }

        // At least one project keyword → on-topic
        foreach ($projectKeywords as $kw) {
            if (str_contains($msgLower, $kw)) {
                return true;
            }
        }

        // Short messages with no keyword: allow if long enough to be a real question
        return mb_strlen(trim($message)) > 20;
    }


    private function getLocalGeneralResponse(string $message, string $userRole): string
    {
        $messageLower = mb_strtolower($message);

        if (str_contains($messageLower, 'command') || str_contains($messageLower, 'passer') || str_contains($messageLower, 'achat') || str_contains($messageLower, 'طلب') || str_contains($messageLower, 'شراء')) {
            if (!in_array($userRole, ['SUPER_ADMIN', 'RESPONSABLE_FB', 'CHEF_CUISINE', 'CHEF_MAGASIN', 'RESPONSABLE_ACHAT'])) {
                return "Acces non autorise / دخول غير مصرح : Votre role de {$userRole} ne vous permet pas de gerer ou consulter les commandes internes.";
            }
            return "Pour passer une commande interne : 1. Accedez a la page Commandes Internes dans le menu lateral. 2. Cliquez sur le bouton Nouvelle Commande en haut a droite. 3. Selectionnez les produits requis dans notre catalogue. 4. Confirmez la quantite et soumettez la commande. (لإنشاء طلب: انتقل إلى الطلبات الداخلية -> طلب جديد -> اختر المنتجات -> تأكيد).";
        }

        if (str_contains($messageLower, 'stock') || str_contains($messageLower, 'inventaire') || str_contains($messageLower, 'lots') || str_contains($messageLower, 'مخزون') || str_contains($messageLower, 'مخزن')) {
            if (!in_array($userRole, ['SUPER_ADMIN', 'CHEF_MAGASIN', 'CHEF_CUISINE', 'RESPONSABLE_ACHAT'])) {
                return "Acces non autorise / دخول غير مصرح : Votre role de {$userRole} ne vous permet pas de consulter les stocks ou l'inventaire en reserve.";
            }
            return "Pour consulter et gerer les stocks : Visitez la section Stocks pour voir les quantites disponibles, l'historique des mouvements FIFO et les alertes de seuil critique. (لمراجعة المخزون: توجه إلى قسم المخزون في القائمة الجانبية لمعرفة الكميات والحركات).";
        }

        // Validation / Produits
        if (str_contains($messageLower, 'valid') || str_contains($messageLower, 'approuv') || str_contains($messageLower, 'accepter') || str_contains($messageLower, 'اعتماد') || str_contains($messageLower, 'منتج')) {
            if (!in_array($userRole, ['SUPER_ADMIN', 'RESPONSABLE_ACHAT'])) {
                return "Acces non autorise / دخول غير مصرح : Votre role de {$userRole} ne vous permet pas d'acceder au panneau de validation des produits.";
            }
            return "Pour la validation des produits : Le Responsable Achat peut approuver ou refuser les nouveaux produits dans la section Validation Produits. (لاعتماد المنتجات: توجه إلى قسم اعتماد المنتجات).";
        }

        // Plannings / Horaires
        if (str_contains($messageLower, 'planning') || str_contains($messageLower, 'calendrier') || str_contains($messageLower, 'horaire') || str_contains($messageLower, 'تخطيط') || str_contains($messageLower, 'شفت') || str_contains($messageLower, 'جدول')) {
            if (!in_array($userRole, ['SUPER_ADMIN', 'RESPONSABLE_FB', 'CAISSIER'])) {
                return "Acces non autorise / دخول غير مصرح : Votre role de {$userRole} ne vous permet pas d'acceder aux plannings horaires.";
            }
            return "Pour les plannings et horaires : Accedez a Plannings et Horaires pour voir la repartition hebdomadaire des shifts du personnel. (للجداول الزمنية: توجه إلى قسم التخطيط لمعرفة ورديات العمل).";
        }

        // Hygiene / Rapports
        if (str_contains($messageLower, 'hygiène') || str_contains($messageLower, 'hygiene') || str_contains($messageLower, 'rapport') || str_contains($messageLower, 'صحة') || str_contains($messageLower, 'سلامة') || str_contains($messageLower, 'تقرير')) {
            if (!in_array($userRole, ['SUPER_ADMIN', 'RESPONSABLE_HYGIENE'])) {
                return "Acces non autorise / دخول غير مصرح : Votre role de {$userRole} ne vous permet pas d'acceder aux rapports de conformite hygiene.";
            }
            return "Pour les rapports d'hygiene : Allez dans Rapports d'Hygiene pour creer des audits sanitaires de conformite et enregistrer des alertes allergenes. (لتقارير السلامة الصحية: توجه إلى قسم التقارير لإنشاء أو مراجعة المطابقة).";
        }

        // Default generic response
        return "AeroServe Assistant (Mode Secours / الوضع البديل) : Le service d'intelligence artificielle est temporairement indisponible. Je peux vous guider vers les sections (Commandes, Stocks, Plannings, Hygiene). Que cherchez-vous ? / خدمة الذكاء الاصطناعي غير متاحة حالياً. يمكنني توجيهك إلى الأقسام الرئيسية.";
    }

    /**
     * Helper to retrieve textual user context summary for dynamic system prompt inject.
     */
    private function getUserContextText(User $user): string
    {
        $context  = "=== CONTEXTE DE L'UTILISATEUR CONNECTE ===\n";
        $context .= "ID: {$user->id}\n";
        $context .= "Nom complet: {$user->first_name} {$user->last_name}\n";
        $context .= "Email: {$user->email}\n";
        $context .= "Role: " . ($user->role?->name ?? 'Aucun') . "\n";

        if ($user->pointDeVente) {
            $context .= "Point de vente assigne: {$user->pointDeVente->name} (ID: {$user->pointDeVente->id}, Type: {$user->pointDeVente->type}, Ville: {$user->pointDeVente->location})\n";
        } else {
            $context .= "Point de vente assigne: Aucun point de vente specifique.\n";
        }

        $plannings = \App\Models\Planning::with('pointDeVente')
            ->where('user_id', $user->id)
            ->orderBy('date', 'desc')
            ->limit(5)
            ->get();

        if ($plannings->isNotEmpty()) {
            $context .= "\nPlannings/Shifts de travail recents et futurs :\n";
            foreach ($plannings as $p) {
                $dateStr = $p->date instanceof \DateTimeInterface ? $p->date->format('Y-m-d') : $p->date;
                if ($p->is_day_off) {
                    $context .= "- Le {$dateStr} : Jour de repos (Day Off)\n";
                } else {
                    $pdvName  = $p->pointDeVente ? $p->pointDeVente->name : 'N/A';
                    $context .= "- Le {$dateStr} : Shift {$p->start_time} - {$p->end_time} a {$pdvName} (Statut: {$p->day_status})\n";
                }
            }
        } else {
            $context .= "\nAucun planning ou shift de travail enregistre.\n";
        }

        $orders = \App\Models\InternalOrder::with('pointDeVente')
            ->where('created_by', $user->id)
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get();

        if ($orders->isNotEmpty()) {
            $context .= "\nCommandes Internes creees par l'utilisateur :\n";
            foreach ($orders as $o) {
                $pdvName  = $o->pointDeVente ? $o->pointDeVente->name : 'General';
                $context .= "- Commande #{$o->id} (Type: {$o->type}, Statut: {$o->status}, PDV: {$pdvName}, Date de livraison: {$o->delivery_date})\n";
            }
        } else {
            $context .= "\nAucune commande interne creee par cet utilisateur.\n";
        }

        return $context;
    }

 
    private function detectMessageLanguage(string $message): string
    {
        $msgLower = mb_strtolower($message);

        // 1. Arabic script check
        if (preg_match('/\p{Arabic}/u', $message)) {
            return 'ar';
        }

        // 2. Spanish keywords
        $esKeywords = ['este', 'producto', 'sano', 'salud', 'diabetico', 'alerg', 'ingrediente', 'comer', 'contiene', 'seguro'];
        // 3. Italian keywords
        $itKeywords = ['questo', 'prodotto', 'sano', 'salute', 'diabetico', 'allergi', 'ingrediente', 'mangiare', 'contiene', 'sicuro'];
        // 4. German keywords
        $deKeywords = ['dieses', 'produkt', 'gesund', 'gesundheit', 'diabetiker', 'allergie', 'zutat', 'essen', 'enthalt', 'sicher'];
        // 5. French keywords
        $frKeywords = ['est-ce', 'produit', 'sain', 'sante', 'diabete', 'diabetique', 'allerg', 'ingredient', 'manger', 'contient', 'securite'];
        // 6. English keywords
        $enKeywords = ['is', 'this', 'product', 'safe', 'healthy', 'health', 'diabet', 'allerg', 'ingredient', 'contain', 'lactose', 'gluten'];

        $esCount = 0;
        foreach ($esKeywords as $kw) {
            if (str_contains($msgLower, $kw)) $esCount++;
        }

        $itCount = 0;
        foreach ($itKeywords as $kw) {
            if (str_contains($msgLower, $kw)) $itCount++;
        }

        $deCount = 0;
        foreach ($deKeywords as $kw) {
            if (str_contains($msgLower, $kw)) $deCount++;
        }

        $frCount = 0;
        foreach ($frKeywords as $kw) {
            if (str_contains($msgLower, $kw)) $frCount++;
        }

        $enCount = 0;
        foreach ($enKeywords as $kw) {
            if (str_contains($msgLower, $kw)) $enCount++;
        }

        $scores = [
            'es' => $esCount,
            'it' => $itCount,
            'de' => $deCount,
            'fr' => $frCount,
            'en' => $enCount
        ];

        arsort($scores);
        $bestLang = key($scores);
        $bestScore = current($scores);

        if ($bestScore > 0) {
            return $bestLang;
        }

        // Default: Check browser Accept-Language header
        $acceptLang = request()->header('Accept-Language');
        if ($acceptLang) {
            $langs = explode(',', $acceptLang);
            $primary = strtolower(substr(trim($langs[0]), 0, 2));
            if (in_array($primary, ['en', 'fr', 'ar', 'es', 'it', 'de'])) {
                return $primary;
            }
        }

        return 'en';
    }
}
