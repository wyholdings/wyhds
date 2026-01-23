<?php

namespace App\Controllers;

class MessageController
{
    public function send($msg, $subject, $receiver_number, $sender_number='010-4928-4236')
    {
        /**************** 문자전송하기 예제 필독항목 ******************/
        /* 동일내용의 문자내용을 다수에게 동시 전송하실 수 있습니다
        /* 대량전송시에는 반드시 컴마분기하여 1천건씩 설정 후 이용하시기 바랍니다. (1건씩 반복하여 전송하시면 초당 10~20건정도 발송되며 컨텍팅이 지연될 수 있습니다.)
        /* 전화번호별 내용이 각각 다른 문자를 다수에게 보내실 경우에는 send 가 아닌 send_mass(예제:curl_send_mass.html)를 이용하시기 바랍니다.

        /****************** 인증정보 시작 ******************/
        $sms_url = "https://apis.aligo.in/send/"; // 전송요청 URL
        $sms['user_id'] = "wyhds05"; // SMS 아이디
        $sms['key'] = "s4quv1hhrlrkkepdjcue03focnkueffc";//인증키
        /****************** 인증정보 끝 ********************/

        /****************** 전송정보 설정시작 ****************/
        $rdate = ''; // 예약일자 - 20161004 : 2016-10-04일기준
        $rtime = ''; // 예약시간 - 1930 : 오후 7시30분
        $testmode_yn = 'N'; // Y 인경우 실제문자 전송X , 자동취소(환불) 처리
        /****************** 전송정보 설정끝 ***************/

        $sms['msg'] = stripslashes($msg);
        $sms['receiver'] = $receiver_number;
        $sms['destination'] = $receiver_number;
        $sms['sender'] = $sender_number;
        $sms['rdate'] = $rdate;
        $sms['rtime'] = $rtime;
        $sms['testmode_yn'] = empty($testmode_yn) ? '' : $testmode_yn;
        $sms['title'] = $subject;
        $sms['msg_type'] = 'LMS'; //  SMS, LMS, MMS등 메세지 타입을 지정
        // 만일 $_FILES 로 직접 Request POST된 파일을 사용하시는 경우 move_uploaded_file 로 저장 후 저장된 경로를 사용하셔야 합니다.
        if(!empty($_FILES['image']['tmp_name'])) {
            $tmp_filetype = mime_content_type($_FILES['image']['tmp_name']); 
            if($tmp_filetype != 'image/png' && $tmp_filetype != 'image/jpg' && $tmp_filetype != 'image/jpeg') $_POST['image'] = '';
            else {
                $_savePath = "./".uniqid(); // PHP의 권한이 허용된 디렉토리를 지정
                if(move_uploaded_file($_FILES['file']['tmp_name'], $_savePath)) {
                    $_POST['image'] = $_savePath;
                }
            }
        }
        
        /*****/
        $host_info = explode("/", $sms_url);
        $port = $host_info[0] == 'https:' ? 443 : 80;

        $oCurl = curl_init();
        curl_setopt($oCurl, CURLOPT_PORT, $port);
        curl_setopt($oCurl, CURLOPT_URL, $sms_url);
        curl_setopt($oCurl, CURLOPT_POST, 1);
        curl_setopt($oCurl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($oCurl, CURLOPT_POSTFIELDS, $sms);
        curl_setopt($oCurl, CURLOPT_SSL_VERIFYPEER, FALSE);
        $ret = curl_exec($oCurl);
        curl_close($oCurl);

        //echo $ret;
        $retArr = json_decode($ret); // 결과배열
        // print_r($retArr); // Response 출력 (연동작업시 확인용)

        /**** Response 항목 안내 ****
        // result_code : 전송성공유무 (성공:1 / 실패: -100 부터 -999)
        // message : success (성공시) / reserved (예약성공시) / 그외 (실패상세사유가 포함됩니다)
        // msg_id : 메세지 고유ID = 고유값을 반드시 기록해 놓으셔야 sms_list API를 통해 전화번호별 성공/실패 유무를 확인하실 수 있습니다
        // error_cnt : 에러갯수 = receiver 에 포함된 전화번호중 문자전송이 실패한 갯수
        // success_cnt : 성공갯수 = 이동통신사에 전송요청된 갯수
        // msg_type : 전송된 메세지 타입 = SMS / LMS / MMS (보내신 타입과 다른경우 로그로 기록하여 확인하셔야 합니다)
        /**** Response 예문 끝 ****/
    }

}