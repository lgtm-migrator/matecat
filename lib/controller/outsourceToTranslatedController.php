<?php
include_once INIT::$MODEL_ROOT . "/queries.php";

class outsourceToTranslatedController extends ajaxController {
	
	private $pid;
	private $ppassword;
	private $jobList;

    public function __construct() {

        //SESSION ENABLED
        parent::__construct();

        $filterArgs = array(
                'pid'             => array( 'filter' => FILTER_SANITIZE_NUMBER_INT ),
                'ppassword'       => array( 'filter' => FILTER_SANITIZE_STRING, 'flags' => FILTER_FLAG_STRIP_LOW | FILTER_FLAG_STRIP_HIGH ),
                'jobs'            => array( 'filter' => FILTER_SANITIZE_STRING, 'flags' => FILTER_REQUIRE_ARRAY  | FILTER_FLAG_STRIP_LOW | FILTER_FLAG_STRIP_HIGH ),
        );

        $__postInput = filter_input_array( INPUT_POST, $filterArgs );

        //NOTE: This is for debug purpose only,
        //NOTE: Global $_POST Overriding from CLI
        //$__postInput = filter_var_array( $_POST, $filterArgs );

        $this->pid       = $__postInput[ 'pid' ];
        $this->ppassword = $__postInput[ 'ppassword' ];
        $this->jobList   = $__postInput[ 'jobs' ];

        if( empty( $this->pid ) ){
            $this->result[ 'errors' ][] = array( "code" => -1, "message" => "No id project provided" );
        }

        if( empty( $this->ppassword ) ){
            $this->result[ 'errors' ][] = array( "code" => -2, "message" => "No project Password Provided" );
        }

        if( empty( $this->jobList ) ){
            $this->result[ 'errors' ][] = array( "code" => -3, "message" => "No job list Provided" );
        }

        if( !empty( $this->result[ 'errors' ] ) ){
            return -1; // ERROR
        }

    }

    public function doAction() {


        $raw_volAnalysis = file_get_contents( INIT::$HTTPHOST . INIT::$BASEURL . "api/status?id_project=" . $this->pid . "&project_pass=" . $this->ppassword );

        $volAnalysis = json_decode( $raw_volAnalysis, true );
        $_jobLangs  = array();

//        Log::doLog( $volAnalysis );

        $options = array(
                CURLOPT_HEADER => false,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => 0,
                CURLOPT_USERAGENT => "Matecat-Cattool/v" . INIT::$BUILD_NUMBER,
                CURLOPT_CONNECTTIMEOUT => 2
        );

//        unset($_SESSION['outsource_to_translated_cache']);
//        Log::doLog( $_SESSION );

        $cache_cart = Shop_Cart::getInstance( 'outsource_to_translated_cache' );

        //prepare handlers for curl to quote service
        $mh = new MultiCurlHandler();
        foreach( $this->jobList as $job ){

//            Log::doLog( $job );
//            Log::doLog( $volAnalysis[ 'data' ] );

            //trim decimals to int
            $job_payableWords =  (int)$volAnalysis[ 'data' ][ 'jobs' ][ $job[ 'jid' ] ][ 'totals' ][ $job['jpassword'] ]['TOTAL_PAYABLE'][0];

            /*
             * //languages are in the form:
             *
             *     "langpairs":{
             *          "5888-e94bd2f79afd":"en-GB-fr-FR",
             *          "5889-c853a841dafd":"en-GB-de-DE",
             *          "5890-e852ca45c66e":"en-GB-it-IT",
             *          "5891-b43f2f067319":"en-GB-es-ES"
             *   },
             *
             */
            $langPairs = $volAnalysis[ 'jobs' ][ 'langpairs' ][ $job[ 'jid' ] . "-" .$job['jpassword'] ];


            $_langPairs_array = explode( "-", $langPairs );
            $source = $_langPairs_array[0] . "-" . $_langPairs_array[1];
            $target = $_langPairs_array[2] . "-" . $_langPairs_array[3];

            //save langpairs of the jobs
            $_jobLangs[ $job[ 'jid' ] . "-" . $job[ 'jpassword' ] ][ 'source' ] = $source;
            $_jobLangs[ $job[ 'jid' ] . "-" . $job[ 'jpassword' ] ][ 'target' ] = $target;

            $url = "http://www.translated.net/hts/?f=quote&cid=htsdemo&p=htsdemo5&s=$source&t=$target&pn=MATECAT_{$job[ 'jid' ]}-{$job['jpassword']}&w=$job_payableWords";

            if( !$cache_cart->itemExists( $job[ 'jid' ] . "-" . $job['jpassword'] ) ){
                Log::doLog( "Not Found in Cache. Call url for Quote:" . $url );
                $tokenHash = $mh->createResource( $url, $options, $job[ 'jid' ] . "-" .$job['jpassword'] );
            }

        }

        $mh->multiExec();

        $res = $mh->getAllContents();

        //fetch contents and store in cache if there are
        foreach( $res as $jpid => $quote ){

            /*
             * Quotes are plain text line feed separated fields in the form:
             *   1
             *   OK
             *   2014-04-16T09:30:00Z
             *   488
             *   46.36
             *   11140320
             */

            $result_quote = explode( "\n", $quote );

            $itemCart                     = new Shop_Item();
            $itemCart[ 'id' ]            = $jpid;
            $itemCart[ 'name' ]          = "MATECAT_$jpid";
            $itemCart[ 'price' ]         = $result_quote[ 4 ];
            $itemCart[ 'delivery_date' ] = $result_quote[ 2 ];
            $itemCart[ 'source' ]        = $_jobLangs[ $jpid ]['source'];
            $itemCart[ 'target' ]        = $_jobLangs[ $jpid ]['target'];
            $cache_cart->addItem( $itemCart );

        }

        $shopping_cart = Shop_Cart::getInstance( 'outsource_to_translated' );
        $shopping_cart->emptyCart();

        //now get the right contents
        foreach ( $this->jobList as $job ){
            $shopping_cart->addItem( $cache_cart->getItem( $job[ 'jid' ] . "-" .$job['jpassword'] ) );
        }

        $client_output = $shopping_cart->getCart();

//        Log::doLog( $client_output );
//        $client_output[] = array( 'id' => $jpid, 'price' => $result_quote[4], 'delivery_date' => $result_quote[2] );

        $this->result[ 'code' ] = 1;
        $this->result[ 'data' ] = array_values( $client_output );
        $this->result[ 'return_url' ] = array(
            'url_ok' => INIT::$HTTPHOST . INIT::$BASEURL,
            'url_ko' => INIT::$HTTPHOST . INIT::$BASEURL,
        );

    }

}

?>
