<?php

$manifest_path = dirname(__FILE__) .  '/manifest.json';
if(!file_exists($manifest_path)){
  throw new Exception('Must have manifest.json in your root folder.');
}
$manifest = json_decode(file_get_contents($manifest_path), true);


function curry($func, $arity) {
  return create_function('', "
        \$args = func_get_args();
        if(count(\$args) >= $arity)
            return call_user_func_array('$func', \$args);
        \$args = var_export(\$args, 1);
        return create_function('','
            \$a = func_get_args();
            \$z = ' . \$args . ';
            \$a = array_merge(\$z,\$a);
            return call_user_func_array(\'$func\', \$a);
        ');
    ");
}


Class Proxy{
  private $manifest = '';
  private $script = '';
  private $css = '';
  private $dir = '';
  private $replace_type = '';

  function __construct($packages='all' , $replace='online' ){

    if(!strlen($packages)) $packages='all';
    if(!strlen($replace)) $replace='online';

    global $manifest;
    $this->manifest = $manifest;


    $this->replace_type = $replace;
    $this->dir = dirname(__FILE__);

    //拼接文件
    $this->parseBase();

    foreach( explode(',' , $packages) as $package ) $this->parseSource($package);

    $this->parseCss($package);
    
    //替换
    $this->replaceSig();

    if( array_key_exists('file_inject' , $this->manifest) ){
      $this->injectFile();
    }
  }

  private function parseBase(){

    if( array_key_exists( 'base' , $this->manifest ) ){
      $sources = (array)$this->manifest['base'];
      foreach( $sources as $source ){
        $source = $this->dir . '/' . $source;
        if( !file_exists( $source ) ) {
          echo $source . 'not found.\n';
          continue;
        }
        $this->script = $this->script . file_get_contents($source);
      }
    }
  }

  private function parseSource($package){
    $sources = $this->manifest['sources'][$package];
    

    foreach( (array)$sources as $source ){
      $source = $this->dir . '/' . $source;
      if( !file_exists( $source ) ) {
        echo $source . 'not found.\n';
        continue;
      }


      $file_content = file_get_contents($source);
      if( array_key_exists( 'parent' ,  $this->manifest) ){

        if( file_exists($this->manifest['parent']) ){
          if( preg_match( '/@template/i' , $file_content ) > 0 ){
            $parent = file_get_contents($this->manifest['parent']);
            $file_content = $this->parseParent($parent , $file_content);
          }
        }
      }
      
      $this->script = $this->script . $file_content ;

    }


  }

  private function parseParent($parent , $child){
    $pattern = '/##(\w+)##/i';
    
    //preg_match_all($pattern , $parent , $matches);
    //$token_replace = $matches[1];

    $child_define = '/@define\s+(\w+)\s*(\w+)\s*[\s\r\n]/i';

    preg_match_all($child_define , $child , $matches);
    $child_key = $matches[1];
    $child_value = $matches[2];

    if( !function_exists('replaceParent') ){
      function replaceParent($child_key,$child_value,$matches){
        $result = $matches[1];
        
        foreach( (array)$child_key as $key=>$value ){
          if( $value == $result ){
            return $child_value[$key];
          }
        }
      };
    }

    $callback = curry( replaceParent , 3 );

    $file = preg_replace( '/##file##/i' , $child , $parent);
    
    $file = preg_replace_callback( $pattern , $callback($child_key,$child_value) , $file );

    return $file;
  }

  private function replaceSig(){
    $pattern = '/%#(\w+)#%/i';


    $this->script = preg_replace_callback( $pattern , array($this , 'replaceFunc') , $this->script );
  }

  private function injectFile(){
    $pattern = '/#%(\w+)%#/i';
    $this->script = preg_replace_callback( $pattern , array($this , 'injectFunc') , $this->script );
  }

  private function parseCss($package){
    if(!isset($this->manifest['css'][$package]))return;
    
    $sources = $this->manifest['css'][$package];

    foreach( (array)$sources as $source ){
      $source = $this->dir . '/' . $source;
      if( !file_exists( $source ) ) {
        echo $source . 'not found.\n';
        continue;
      }
      
      $this->css = $this->css . file_get_contents($source);

    }
    
    if( strlen($this->css) && isset($this->manifest['css']['method']) ){
      $this->css = $this->manifest['css']['method'] . '(' . json_encode($this->css) . ", ['$package']);";
    }

  }

  private function replaceFunc($matches){
    return $this->manifest['replace'][$this->replace_type][strtolower($matches[1])];
  }

  private function injectFunc($matches){
    $file_path = $this->manifest['file_inject'][strtolower($matches[1])];
    if( file_exists($file_path) ){
      $file = file_get_contents($file_path);
      return $file;
    }
  }

  public function printScript(){
    echo $this->script;

    echo $this->css;
  }

  public function getScript(){
    return $this->script . $this->css;
  }

}

if( isset($argv) ){
  $source_pkg = $argv[1];

  $target = $argv[2];
  $replace = $argv[3];

  if( !isset($source_pkg) || !isset($target) ){
      throw new Exception('Must have two args when export.');
  }
  if( $source_pkg == '*' ){  //多匹配
    if( !is_dir($target) )  {
      $old = umask(0);
      mkdir($target , 0777);
      umask($old);
    }
    global $manifest;

    foreach( $manifest['sources'] as $item=>$value ){
      $file = new Proxy($item , $replace);
      $fp = @fopen( $target . '/' . $item . '.js' , 'w' );
      @fwrite($fp , $file->getScript());
      @fclose($fp);
    }
  }else{
    $proxy = new Proxy($source_pkg , $replace);
    
    $fp = @fopen($target , 'w');
    @fwrite($fp , $proxy->getScript());
    @fclose($fp);
  }

}else{
  header('Content-type:application/javascript');
  if($_GET){
    $s = array_key_exists('s' , $_GET) ? $_GET['s'] :'';
    $replace = array_key_exists('replace' , $_GET) ? $_GET['replace'] : null;

    $proxy = new Proxy($s , $replace );
  }else{
    $proxy = new Proxy();
  }
  $proxy->printScript();
}


?>
