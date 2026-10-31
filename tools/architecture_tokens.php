<?php
declare(strict_types=1);
if(!function_exists('token_get_all')){fwrite(STDERR,"Architecture checks require the development tokenizer extension.\n");exit(1);}
$result=[];
foreach(json_decode(stream_get_contents(STDIN),true,64,JSON_THROW_ON_ERROR) as $file){
    $tokens=[];foreach(token_get_all(file_get_contents($file),TOKEN_PARSE) as $token){$tokens[]=is_array($token)?[token_name($token[0]),$token[1],$token[2]]:['CHAR',$token,0];}$result[$file]=$tokens;
}
echo json_encode($result,JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE);
