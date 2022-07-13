<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;
use App\Services\amoAPI\amoCRM;
use App\Models\Account;
use App\Models\Lead;
use App\Models\changeStage;

class LeadController extends Controller
{
    public function __construct()
    {
    }

    public function get($id, Request $request)
    {
        $lead = new Lead();
        $crtlead = $lead->get($id);

        if ($crtlead) {
            $crtlead = [
                'data' => [
                    'id_target_lead' => $crtlead->id_target_lead,
                    'related_lead'   => $crtlead->related_lead,
                ],
            ];
        } else {
            $crtlead = [
                'data' => false,
            ];
        }

        return $crtlead;
    }

    public function createMortgage(Request $request)
    {
        $account     = new Account();
        $authData    = $account->getAuthData();
        $amo         = new amoCRM($authData);
        $inputData   = $request->all();
        $hauptLeadId = $inputData['hauptLeadId'] ?? false;
        $from        = $inputData['from'] ?? false;
        $hauptLead = $amo->findLeadById($hauptLeadId);
        $mainLeadRespManId = 1033945;

        if (
            $hauptLead['code'] === 404 ||
            $hauptLead['code'] === 400
        ) {
            return response(
                ['An error occurred in the server request while searching for a main lead'],
                $hauptLead['code']
            );
        } else if ($hauptLead['code'] === 204) {
            return response(['Lead not found'], 404);
        }

        $mainContactId = null;
        $contacts = $hauptLead['body']['_embedded']['contacts'];

        for ($contactIndex = 0; $contactIndex < count($contacts); $contactIndex++) {
            if ($contacts[$contactIndex]['is_main']) {
                $mainContactId = (int) $contacts[$contactIndex]['id'];
                break;
            }
        }

        $contact = $amo->findContactById($mainContactId);

        if (
            $contact['code'] === 404 ||
            $contact['code'] === 400
        ) {
            return response(
                ['An error occurred in the server request while looking for a contact'],
                $contact['code']
            );
        } else if ($contact['code'] === 204) {
            return response(['Contact not found'], 404);
        }

        $leads                = $contact['body']['_embedded']['leads'];
        $mortgage_pipeline_id = (int) config('app.amoCRM.mortgage_pipeline_id');
        $haveMortgage         = false;
        $mortgageLeadId       = false;

        for ($leadIndex = 0; $leadIndex < count($leads); $leadIndex++) {
            $lead = $amo->findLeadById($leads[$leadIndex]['id']);
            $currentPipelineid = $lead['body']['pipeline_id'];

            if (
                (int) $mortgage_pipeline_id === (int) $currentPipelineid &&
                (int) $lead['body']['status_id'] !== 142 &&
                (int) $lead['body']['status_id'] !== 143
            ) {
                $haveMortgage   = true;
                $mortgageLeadId = $lead['body']['id'];
            }
        }

        if ($haveMortgage) { /* eine Aufgabe für gefundenen Lead stellen */
            Log::info(
                __METHOD__,
                ['Active Hypothek ist gefunden: ' . $mortgageLeadId . '. Eine Aufgabe muss gestellt werden']
            ); //DEBUG //DELETE

            $amo->createTask(
                (int) config('app.amoCRM.mortgage_responsible_user_id'),
                $mortgageLeadId,
                time() + 10800,
                'Менеджер повторно отправил запрос на ипотеку.'
            );

            Lead::create([ /* Datenbankeintrag fürs Hauptlead */
                'id_target_lead'  => $hauptLeadId,
                'related_lead'    => $mortgageLeadId,
            ]);
            Lead::create([ /* Datenbankeintrag für die Hypothek */
                'id_target_lead'  => $mortgageLeadId,
                'related_lead'    => $hauptLeadId,
            ]);

            return response(
                ['OK. Active mortgage is found. A task must be set'],
                200
            );
        } else { /* Lead erstellen und zwar das Hauptlead kopieren */
            Log::info(
                __METHOD__,
                ['OK. Active mortgage is not found. A new lead must be created']
            );

            $newLead = $amo->copyLead($hauptLeadId);

            if ($newLead) {
                $amo->updateLead([[
                    "id" => (int)$newLead,
                    'custom_fields_values' => [[
                        'field_id' => $mainLeadRespManId,
                        'values' => [[
                            'value' => "test name of manager"
                        ]]
                    ]]
                ]]);
                $amo->createTask(
                    (int) config('app.amoCRM.mortgage_responsible_user_id'),
                    $newLead,
                    time() + 3600,
                    'Клиент выбрал квартиру. Хочет открыть ипотеку, свяжись с клиентом'
                );
                $amo->addTag($hauptLeadId, 'Отправлен ИБ');

                Lead::create([ /* Datenbankeintrag fürs Hauptlead */
                    'id_target_lead'  => $hauptLeadId,
                    'related_lead'    => $newLead,
                ]);
                Lead::create([ /* Datenbankeintrag für die Hypothek */
                    'id_target_lead'  => $newLead,
                    'related_lead'    => $hauptLeadId,
                ]);
            } else {
                Log::info(
                    __METHOD__,
                    [json_encode($newLead)]
                );
            }

            return response(
                ['OK. Active mortgage is not found. A new lead must be created'],
                200
            );
        }
    }

    public function deleteLeadWithRelated(Request $request)
    {
        $lead = new Lead();
        $inputData = $request->all();

        $leadId = $inputData['leads']['delete'][0]['id'];

        Log::info(__METHOD__, [$leadId]); // DEBUG

        return $lead->deleteWithRelated($leadId) ? response(['OK'], 200) : response(['ERROR'], 400);
    }

    public function changeStage(Request $request)
    {
        $inputData = $request->all();

        Log::info(__METHOD__, $inputData); // DEBUG

        $dataLead = $inputData['leads']['status'][0];

        changeStage::updateOrCreate(
            ['lead_id' => (int) $dataLead['id'],],
            ['lead'    => json_encode($dataLead)]
        );

        return response(['OK'], 200);
    }

    public function cronChangeStage()
    {
        return 200; // DELETE

        $account  = new Account();
        $authData = $account->getAuthData();
        $amo      = new amoCRM($authData);

        $objLead = new Lead();

        $leadsCount                 = 10;
        $MORTGAGE_PIPELINE_ID       = 5537869;


        $loss_reason_comment_id     = 755700;

        $mortgageApproved_status_id = 43332213;

        $paymentForm_field_id       = 982273;
        $paymentForm_field_mortgage = 1525765;

        $haupt_loss_reason_id       = 981809;
        $loss_reason_id             = 982017;
        $loss_reason_close_by_man   = 1311718;

        $leads          = changeStage::take($leadsCount)->get();
        $objChangeStage = new changeStage();

        foreach ($leads as $lead) {
            $leadData = json_decode($lead->lead, true);
            $lead_id  = (int) $leadData['id'];
            $ausDB    = Lead::where('id_target_lead', $lead_id)->count();

            if ($ausDB) {
                echo 'leadData aus der Datenbank<br>';
                echo '<pre>';
                print_r($leadData);
                echo '</pre>';

                $responsible_user_id      = (int) $leadData['responsible_user_id'];
                $pipeline_id              = (int) $leadData['pipeline_id'];
                $status_id                = (int) $leadData['status_id'];
                $stage_loss               = 143;
                $stage_success            = 142;
                $stage_booking            = 48941119;

                // Mortgage-Stufen
                $FILING_AN_APPLICATION      = 43332207;
                $WAITING_FOR_BANK_RESPONSE  = 43332210;
                $MORTGAGE_APPROVED          = 43332213;
                $SENDING_DATA_PREPARING_DDU = 43332216;
                $DDU_TRANSFERRED_TO_BANK    = 43332225;
                $WAITING_FOR_ESCROW_OPENING = 43332228;
                $SIGNING_DEAL               = 43332231;
                $SUBMITTED_FOR_REGISTRATION = 43332234;
                $CONTROL_RECEIPT_FUNDS      = 43332240;

                if ($pipeline_id === $MORTGAGE_PIPELINE_ID) {
                    echo $lead_id . ' Es ist Hypothek-Pipeline<br>';
                    Log::info(__METHOD__, [$lead_id . ' Es ist Hypothek-Pipeline']);

                    if ($status_id === $mortgageApproved_status_id) // TODO Hypothek wurde genehmigt
                    {
                        echo $lead_id . ' Hypothek genehmigt<br>';
                        Log::info(__METHOD__, [$lead_id . ' Hypothek genehmigt']);

                        $crtLead      = Lead::where('id_target_lead', $lead_id)->first();
                        $hauptLeadId  = (int) $crtLead->related_lead;

                        $hauptLead = $amo->findLeadById($hauptLeadId);

                        if ($hauptLead['code'] === 404 || $hauptLead['code'] === 400) {
                            continue;
                            //return response( [ 'Bei der Suche nach einem hauptLead ist ein Fehler in der Serveranfrage aufgetreten' ], $hauptLead[ 'code' ] );
                        } else if ($hauptLead['code'] === 204) {
                            continue;
                            //return response( [ 'hauptLead ist nicht gefunden' ], 404 );
                        }

                        $hauptLead = $hauptLead['body'];

                        $hauptLead_responsible_user_id  = (int) $hauptLead['responsible_user_id'];

                        echo 'hauptLead<br>';
                        echo '<pre>';
                        print_r($hauptLead);
                        echo '</pre>';

                        $amo->createTask(
                            $hauptLead_responsible_user_id,
                            $hauptLeadId,
                            time() + 10800,
                            'Клиенту одобрена ипотека'
                        );
                    } else if ($status_id === $stage_loss) // TODO Hypothek-Lead ist geschlossen
                    {
                        echo $lead_id . ' Hypothek-Lead ist geschlossen<br>';
                        Log::info(__METHOD__, [$lead_id . ' Hypothek-Lead ist geschlossen']);

                        $crtLead      = Lead::where('id_target_lead', $lead_id)->first();
                        $hauptLeadId  = (int) $crtLead->related_lead;

                        echo $hauptLeadId . ' Dieses Haupt-Lead muss überprüft werden<br>';

                        $hauptLead = $amo->findLeadById($hauptLeadId);

                        if ($hauptLead['code'] === 404 || $hauptLead['code'] === 400) {
                            continue;
                            //return response( [ 'Bei der Suche nach einem hauptLead ist ein Fehler in der Serveranfrage aufgetreten' ], $hauptLead[ 'code' ] );
                        } else if ($hauptLead['code'] === 204) {
                            continue;
                            //return response( [ 'hauptLead ist nicht gefunden' ], 404 );
                        }

                        $hauptLead = $hauptLead['body'];

                        $hauptLead_status_id            = (int) $hauptLead['status_id'];
                        $hauptLead_responsible_user_id  = (int) $hauptLead['responsible_user_id'];

                        echo 'hauptLead<br>';
                        echo '<pre>';
                        print_r($hauptLead);
                        echo '</pre>';

                        if (
                            $hauptLead_status_id !== $stage_loss
                            &&
                            $hauptLead_status_id !== $stage_success
                        ) {
                            // Aufgabe in der Hauptlead stellen
                            $custom_fields    = $leadData['custom_fields'];
                            $crt_loss_reason  = false;

                            for ($cfIndex = 0; $cfIndex < count($custom_fields); $cfIndex++) {
                                if ((int) $custom_fields[$cfIndex]['id'] === $loss_reason_id) {
                                    $crt_loss_reason = $custom_fields[$cfIndex];

                                    break;
                                }
                            }

                            echo 'crt_loss_reason<br>';
                            echo '<pre>';
                            print_r($crt_loss_reason);
                            echo '</pre>';

                            $amo->createTask(
                                $hauptLead_responsible_user_id,
                                $hauptLeadId,
                                time() + 10800,
                                'Сделка по ипотеке “закрытаа не реализована” с причиной отказа: ' . $crt_loss_reason['values'][0]['value']
                            );
                        }
                    }
                } else {
                    echo $lead_id . ' Es ist nicht Hypothek-Pipeline<br>';
                    Log::info(__METHOD__, [$lead_id . ' Es ist nicht Hypothek-Pipeline']);

                    if ($status_id === $stage_booking) { /* booking stage */
                        echo $lead_id . ' Es ist booking stage<br>';

                        $custom_fields      = $leadData['custom_fields'];
                        $crtPaymentMortgage = false;

                        for ($cfIndex = 0; $cfIndex < count($custom_fields); $cfIndex++) {
                            if ((int) $custom_fields[$cfIndex]['id'] === $paymentForm_field_id) {
                                $crtPaymentMortgage = $custom_fields[$cfIndex]['values']['enum'];

                                break;
                            }
                        }

                        echo 'current PaymentMortgage: ' . $crtPaymentMortgage . '<br>';
                        echo 'target PaymentMortgage: ' . $paymentForm_field_mortgage . '<br>';

                        if ((int) $crtPaymentMortgage === (int) $paymentForm_field_mortgage) {
                            echo 'Dieses Lead ist target<br>';

                            $crtLead        = Lead::where('id_target_lead', $lead_id)->first();
                            $hypothekLeadId = (int) $crtLead->related_lead;

                            echo $hypothekLeadId . ' Dieses Hypothek-Lead muss bearbeitet werden<br>';

                            $hypothekLead = $amo->findLeadById($hypothekLeadId);

                            if ($hypothekLead['code'] === 404 || $hypothekLead['code'] === 400) {
                                continue;
                                //return response( [ 'Bei der Suche nach einem hypothekLead ist ein Fehler in der Serveranfrage aufgetreten' ], $hypothekLead[ 'code' ] );
                            } else if ($hypothekLead['code'] === 204) {
                                continue;
                                //return response( [ 'HypothekLead ist nicht gefunden' ], 404 );
                            }

                            $hypothekLead = $hypothekLead['body'];

                            $hypothekLead_responsible_user_id  = (int) $hypothekLead['responsible_user_id'];

                            if (
                                (int) $hypothekLead['status_id'] !== $stage_success &&
                                (int) $hypothekLead['status_id'] !== $FILING_AN_APPLICATION &&
                                (int) $hypothekLead['status_id'] !== $WAITING_FOR_BANK_RESPONSE &&
                                (int) $hypothekLead['status_id'] !== $MORTGAGE_APPROVED &&
                                (int) $hypothekLead['status_id'] !== $SENDING_DATA_PREPARING_DDU &&
                                (int) $hypothekLead['status_id'] !== $DDU_TRANSFERRED_TO_BANK &&
                                (int) $hypothekLead['status_id'] !== $WAITING_FOR_ESCROW_OPENING &&
                                (int) $hypothekLead['status_id'] !== $SIGNING_DEAL &&
                                (int) $hypothekLead['status_id'] !== $SUBMITTED_FOR_REGISTRATION &&
                                (int) $hypothekLead['status_id'] !== $CONTROL_RECEIPT_FUNDS
                            ) {
                                echo $hypothekLeadId . 'Hypotheklead befindet sich vor der Stufe der Antragstellung<br>';

                                $amo->updateLead([[
                                    "id"        => (int) $hypothekLeadId,
                                    "status_id" => $FILING_AN_APPLICATION,
                                ]]);

                                // Aufgabe in der Hypothek-Lead stellen
                                $amo->createTask(
                                    $hypothekLead_responsible_user_id,
                                    $hypothekLeadId,
                                    time() + 10800,
                                    'Клиент забронировал КВ. Созвонись с клиентом и приступи к открытию Ипотеки'
                                );
                            } else if ((int) $hypothekLead['status_id'] === $stage_loss) {
                                // TODO Einen neuen Lead in der Zielstufe erstellen
                                $newLead = $amo->copyLead($lead_id, true);

                                if ($newLead) {
                                    // Aufgabe in der Hypothek-Lead stellen
                                    $amo->createTask(
                                        (int) config('app.amoCRM.mortgage_responsible_user_id'),
                                        $newLead,
                                        time() + 3600,
                                        'Клиент забронировал КВ. Созвонись с клиентом и приступи к открытию Ипотеки'
                                    );
                                }
                            }
                        } else {
                            echo 'Dieses Lead ist nicht target<br>';
                        }
                    } else if ($status_id === $stage_loss) { /* Pipeline-Lead ist geschlossen. Hypotheklead muss zum Ende gebracht werden. */
                        Log::info(
                            __METHOD__,
                            [$lead_id . ' Pipeline-Lead ist geschlossen']
                        ); // DEBUG

                        $crtLead         = Lead::where('id_target_lead', $lead_id)->first();
                        $custom_fields   = $leadData['custom_fields'];
                        $crt_loss_reason = false;

                        for ($cfIndex = 0; $cfIndex < count($custom_fields); $cfIndex++) {
                            if ((int) $custom_fields[$cfIndex]['id'] === $haupt_loss_reason_id) {
                                $crt_loss_reason = $custom_fields[$cfIndex];
                            }
                        }

                        Log::info(
                            __METHOD__,
                            ['Verlust Grund des Pipeline-Leads ist: ' . $crt_loss_reason]
                        ); // DEBUG

                        $amo->updateLead([[
                            "id" => (int) $crtLead->related_lead,
                            "status_id" => $stage_loss,
                            'custom_fields_values' => [
                                [
                                    'field_id' => $loss_reason_id,
                                    'values' => [[
                                        'enum_id' => $loss_reason_close_by_man
                                    ]]
                                ],
                                [
                                    'field_id' => $loss_reason_comment_id,
                                    'values' => [[
                                        'value' => $crt_loss_reason['values'][0]['value']
                                    ]]
                                ]
                            ]
                        ]]);

                        // Aufgabe in der Hypotheklead stellen
                        $amo->createTask(
                            $responsible_user_id,
                            (int) $crtLead->related_lead,
                            time() + 10800,
                            'Сделка менеджера с клиентом в основной воронке перешла в "Закрыто не реализовано". Созвонись с клиентом. Если покупка не актуальна, то закрой все активные задачи. Если покупка актуальна, то свяжись с менеджером и выясни детали, а затем восстанови сделку.'
                        );

                        // Leadsdaten aus der Datenbank entfernen (leads)
                        $objLead->deleteWithRelated((int) $lead_id);
                    }
                }
            }

            $objChangeStage->deleteLead($lead_id);
        }
    }
}
