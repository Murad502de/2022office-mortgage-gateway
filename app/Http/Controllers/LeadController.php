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
        $account  = new Account();
        $authData = $account->getAuthData();
        $amo      = new amoCRM($authData);

        $inputData    = $request->all();
        $hauptLeadId  = $inputData['hauptLeadId'] ?? false;
        $from  = $inputData['from'] ?? false;

        $hauptLead = $amo->findLeadById($hauptLeadId);

        Log::info(__METHOD__, ['hauptLead']);
        Log::info(__METHOD__, [$hauptLead]);

        if ($hauptLead['code'] === 404 || $hauptLead['code'] === 400) {
            return response(['Bei der Suche nach einem hauptLead ist ein Fehler in der Serveranfrage aufgetreten'], $hauptLead['code']);
        } else if ($hauptLead['code'] === 204) {
            return response(['hauptLead ist nicht gefunden'], 404);
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

        if ($contact['code'] === 404 || $contact['code'] === 400) {
            return response(['Bei der Suche nach einem Kontakt ist ein Fehler in der Serveranfrage aufgetreten '], $contact['code']);
        } else if ($contact['code'] === 204) {
            return response(['Contact ist nicht gefunden'], 404);
        }

        $leads                = $contact['body']['_embedded']['leads'];
        $mortgage_pipeline_id = (int) config('app.amoCRM.mortgage_pipeline_id');
        $haveMortgage         = false;
        $mortgageLeadId       = false;

        for ($leadIndex = 0; $leadIndex < count($leads); $leadIndex++) {
            $lead = $amo->findLeadById($leads[$leadIndex]['id']);

            $currentPipelineid = $lead['body']['pipeline_id'];

            if (
                (int) $mortgage_pipeline_id === (int) $currentPipelineid
                &&
                (int) $lead['body']['status_id'] !== 142
                &&
                (int) $lead['body']['status_id'] !== 143
            ) {
                $haveMortgage   = true;
                $mortgageLeadId = $lead['body']['id'];
            }
        }

        if ($haveMortgage) {
            // TODO eine Aufgabe für gefundenen Lead stellen

            Log::info(
                __METHOD__,

                ['Active Hypothek ist gefunden. Eine Aufgabe muss gestellt werden']
            );

            //echo "mortgage id: $mortgageLeadId<br>";

            $amo->createTask(
                (int) config('app.amoCRM.mortgage_responsible_user_id'),
                $mortgageLeadId,
                time() + 10800,
                '
          Менеджер повторно отправил запрос на ипотеку.
        '
            );

            // Datenbankeintrag fürs Hauptlead
            Lead::create(
                [
                    'id_target_lead'  => $hauptLeadId,
                    'related_lead'    => $mortgageLeadId
                ]
            );

            // Datenbankeintrag für die Hypothek
            Lead::create(
                [
                    'id_target_lead'  => $mortgageLeadId,
                    'related_lead'    => $hauptLeadId
                ]
            );

            return response(['OK. Active Hypothek ist gefunden. Eine Aufgabe muss gestellt werden'], 200);
        } else {
            // TODO Lead erstellen und zwar das Hauptlead kopieren

            Log::info(
                __METHOD__,

                ['Active Hypothek ist nicht gefunden. Ein neues Lead muss erstellt werden']
            );

            $newLead = $amo->copyLead($hauptLeadId);

            // echo 'newLead<br>';
            // echo '<pre>';
            // print_r( $newLead );
            // echo '</pre>';

            if ($newLead) {
                $textTask = null;

                switch ($from) {
                    case 'confirm':
                        $textTask = 'Клиент выбрал квартиру. Хочет открыть ипотеку, свяжись с клиентом';
                        break;

                    case 'consult':
                        $textTask = 'Клиент еще не определился с объектом недвижимости. Нужна консультация';
                        break;

                    default:
                        $textTask = 'Менеджер отправил запрос на ипотеку.';
                        break;
                }

                $amo->createTask(
                    (int) config('app.amoCRM.mortgage_responsible_user_id'),
                    $newLead,
                    time() + 3600,
                    $textTask
                );

                $amo->addTag($hauptLeadId, 'Отправлен в Ипотеку');

                // Datenbankeintrag fürs Hauptlead
                Lead::create(
                    [
                        'id_target_lead'  => $hauptLeadId,
                        'related_lead'    => $newLead
                    ]
                );

                // Datenbankeintrag für die Hypothek
                Lead::create(
                    [
                        'id_target_lead'  => $newLead,
                        'related_lead'    => $hauptLeadId
                    ]
                );
            }

            return response(['OK. Active Hypothek ist nicht gefunden. Ein neues Lead muss erstellt werden'], 200);
        }
    }

    public function deleteLeadWithRelated(Request $request)
    {
        $lead = new Lead();
        $inputData = $request->all();

        $leadId = $inputData['leads']['delete'][0]['id'];

        Log::info(
            __METHOD__,

            [$leadId]
        );

        return $lead->deleteWithRelated($leadId) ? response(['OK'], 200) : response(['ERROR'], 400);
    }

    public function changeStage(Request $request)
    {
        $inputData = $request->all();

        Log::info(__METHOD__, $inputData);

        $dataLead = $inputData['leads']['status'][0];

        changeStage::create(
            [
                'lead_id' => (int) $dataLead['id'],
                'lead'    => json_encode($dataLead)
            ]
        );

        return response(['OK'], 200);
    }

    public function cronChangeStage()
    {
        $account  = new Account();
        $authData = $account->getAuthData();
        $amo      = new amoCRM($authData);

        $objLead = new Lead();

        $isDev                      = false;
        $leadsCount                 = 10;
        $MORTGAGE_PIPELINE_ID       = $isDev ? 4799893 : 4691106;
        $loss_reason_id             = $isDev ? 1038771 : 755698;
        $loss_reason_close_by_man   = $isDev ? 618727 : 1311718;
        $loss_reason_comment_id     = $isDev ? 1038773 : 755700;
        $resp_user                  = $isDev ? 7001125 : 7507200;
        $mortgageApproved_status_id = 43332213;
        $paymentForm_field_id       = 589157;
        $paymentForm_field_mortgage = 1262797;
        $haupt_loss_reason_id       = 588811;

        $leads          = changeStage::take($leadsCount)->get();
        $objChangeStage = new changeStage();

        foreach ($leads as $lead) {
            $leadData = json_decode($lead->lead, true);
            $lead_id  = (int) $leadData['id'];

            $ausDB = Lead::where('id_target_lead', $lead_id)->count();

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
                $stage_booking_gub        = 22041337;
                $stage_booking_gub_park   = 41986941;
                $stage_booking_dost       = 33256063;
                $stage_booking_dost_park  = 43058475;

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

                    if ( // TODO booking stage
                        $status_id === $stage_booking_gub
                        ||
                        $status_id === $stage_booking_gub_park
                        ||
                        $status_id === $stage_booking_dost
                        ||
                        $status_id === $stage_booking_dost_park
                    ) {
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
                                (int) $hypothekLead['status_id'] !== $stage_success
                                &&
                                (int) $hypothekLead['status_id'] !== $FILING_AN_APPLICATION
                                &&
                                (int) $hypothekLead['status_id'] !== $WAITING_FOR_BANK_RESPONSE
                                &&
                                (int) $hypothekLead['status_id'] !== $MORTGAGE_APPROVED
                                &&
                                (int) $hypothekLead['status_id'] !== $SENDING_DATA_PREPARING_DDU
                                &&
                                (int) $hypothekLead['status_id'] !== $DDU_TRANSFERRED_TO_BANK
                                &&
                                (int) $hypothekLead['status_id'] !== $WAITING_FOR_ESCROW_OPENING
                                &&
                                (int) $hypothekLead['status_id'] !== $SIGNING_DEAL
                                &&
                                (int) $hypothekLead['status_id'] !== $SUBMITTED_FOR_REGISTRATION
                                &&
                                (int) $hypothekLead['status_id'] !== $CONTROL_RECEIPT_FUNDS
                            ) {
                                echo $hypothekLeadId . ' Hypotheklead befindet sich vor der Stufe der Antragstellung<br>';

                                $amo->updateLead(
                                    [
                                        [
                                            "id"        => (int) $hypothekLeadId,
                                            "status_id" => $FILING_AN_APPLICATION,
                                        ]
                                    ]
                                );

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
                    } else if ($status_id === $stage_loss) // TODO Pipeline-Lead ist geschlossen
                    {
                        echo $lead_id . ' Pipeline-Lead ist geschlossen<br>';
                        Log::info(__METHOD__, [$lead_id . ' Pipeline-Lead ist geschlossen']);

                        $crtLead = Lead::where('id_target_lead', $lead_id)->first();

                        echo $crtLead->related_lead . ' Dieses Hypothek-Lead muss auch geschlossen werden<br>';

                        // Hypotheklead zum Ende bringen
                        $custom_fields    = $leadData['custom_fields'];
                        $crt_loss_reason  = false;

                        for ($cfIndex = 0; $cfIndex < count($custom_fields); $cfIndex++) {
                            if ((int) $custom_fields[$cfIndex]['id'] === $haupt_loss_reason_id) {
                                $crt_loss_reason = $custom_fields[$cfIndex];
                            }
                        }

                        echo 'crt_loss_reason<br>';
                        echo '<pre>';
                        print_r($crt_loss_reason);
                        echo '</pre>';

                        $amo->updateLead(
                            [
                                [
                                    "id"                    => (int) $crtLead->related_lead,
                                    "status_id"             => $stage_loss,
                                    'custom_fields_values'  => [
                                        [
                                            'field_id'  => $loss_reason_id,
                                            'values'    => [
                                                [
                                                    'enum_id' => $loss_reason_close_by_man
                                                ]
                                            ]
                                        ],

                                        [
                                            'field_id' => $loss_reason_comment_id,
                                            'values' => [
                                                [
                                                    'value' => $crt_loss_reason['values'][0]['value']
                                                ]
                                            ]
                                        ]
                                    ]
                                ]
                            ]
                        );

                        // Aufgabe in der Hypotheklead stellen
                        $amo->createTask(
                            $responsible_user_id,
                            (int) $crtLead->related_lead,
                            time() + 10800,
                            '
                Сделка менеджера с клиентом в основной воронке перешла в "Закрыто не реализовано". Созвонись с клиентом. Если покупка не актуальна, то закрой все активные задачи. Если покупка актуальна, то свяжись с менеджером и выясни детали, а затем восстанови сделку.
              '
                        );

                        // Leadsdaten aus der Datenbank entfernen (leads)
                        $objLead->deleteWithRelated((int) $lead_id);
                    }
                }
            }

            // Leadsdaten aus der Datenbank entfernen (change_stage)
            $objChangeStage->deleteLead($lead_id);
        }
    }
}
