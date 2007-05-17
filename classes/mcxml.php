<?
// function: recursvars
function RecursVars(&$xml_array, &$vars){
    if (!is_array($xml_array)) return;
    for ($i=0; $i<count($xml_array); $i++){
                if (is_array($xml_array[$i]->next)) {
                    RecursVars($xml_array[$i]->next, $vars);
                }else {
                            $vars[strtolower($xml_array[$i]->tag)] = $xml_array[$i]->value;
                }
    }
}

 

class Xml{

            var $tag;

            var $value;

            var $attributes;

            var $next;

}

 

function xml2array($xml_string) {

            $Parser = xml_parser_create();

            xml_parser_set_option($Parser, XML_OPTION_CASE_FOLDING, 0);

            xml_parser_set_option($Parser, XML_OPTION_SKIP_WHITE, 1);

            xml_parse_into_struct($Parser, $xml_string, $Xml_Values);

            xml_parser_free($Parser);

            $XmlClass = array();

            $LastObj = array();

            $NowObj = &$XmlClass;

 

            foreach($Xml_Values as $Xml_Key => $Xml_Value){

                        $Index = count($NowObj);

                        if($Xml_Value["type"] == "complete") {

                                    $NowObj[$Index] = new Xml;

                                    $NowObj[$Index]->tag = $Xml_Value["tag"];

                                    $NowObj[$Index]->value = $Xml_Value["value"];

                                    $NowObj[$Index]->attributes = $Xml_Value["attributes"];

                        } elseif($Xml_Value["type"] == "open") {

                                    $NowObj[$Index] = new Xml;

                                    $NowObj[$Index]->tag = $Xml_Value["tag"];

                                    $NowObj[$Index]->value = $Xml_Value["value"];

                                    $NowObj[$Index]->attributes = $Xml_Value["attributes"];

                                    $NowObj[$Index]->next = array();

                                    $LastObj[count($LastObj)] = &$NowObj;

                                    $NowObj = &$NowObj[$Index]->next;

                        } elseif($Xml_Value["type"] == "close") {

                                    $NowObj = &$LastObj[count($LastObj) - 1];

                                    unset($LastObj[count($LastObj) - 1]);

                        }

            }

            return $XmlClass;

}

// array_to_xml

function array_to_xml($array, $level=1) {
    $xml = '';
    if ($level==1) {
        $xml .= '<?xml version="1.0" encoding="UTF-8"?>'.
            "\n<shop_account>\n";
    }
    foreach ($array as $key=>$value) {
        $key = strtolower($key);
        if (is_array($value)) {
            $multi_tags = false;
            foreach($value as $key2=>$value2) {
                if (is_array($value2)) {
                    $xml .= str_repeat("\t",$level)."<$key>\n";
                    $xml .= array_to_xml($value2, $level+1);
                    $xml .= str_repeat("\t",$level)."</$key>\n";
                    $multi_tags = true;
                } else {
                    if (trim($value2)!='') {
                        if (htmlspecialchars($value2)!=$value2) {
                            $xml .= str_repeat("\t",$level).
                                    "<$key><![CDATA[$value2]]>".
                                "</$key>\n";
                        } else {
                            $xml .= str_repeat("\t",$level).
                                "<$key>$value2</$key>\n";
                        }
                    }
                    $multi_tags = true;
                }
            }
            if (!$multi_tags and count($value)>0) {
                $xml .= str_repeat("\t",$level)."<$key>\n";
                $xml .= array_to_xml($value, $level+1);
                $xml .= str_repeat("\t",$level)."</$key>\n";
            }
        } else {
            if (trim($value)!='') {
                if (htmlspecialchars($value)!=$value) {
                    $xml .= str_repeat("\t",$level)."<$key>".
                        "<![CDATA[$value]]></$key>\n";
                } else {
                    $xml .= str_repeat("\t",$level).
                        "<$key>$value</$key>\n";
                }
            }
        }
    }
    if ($level==1) {
        $xml .= "</shop_account>\n";
    }
    return $xml;
}

?>
