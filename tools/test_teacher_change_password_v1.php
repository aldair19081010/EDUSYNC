<?php
/* Contrato estático: php tools/test_teacher_change_password_v1.php */
$path=dirname(__DIR__).'/edusync/api/teacher_change_password.php';
$source=file_get_contents($path);
if($source===false){fwrite(STDERR,"No se encontró la API docente.\n");exit(1);}
$checks=[
  'Solo POST'=>strpos($source,"REQUEST_METHOD")!==false && strpos($source,"'POST'")!==false,
  'Sesión del docente'=>strpos($source,"login_type")!==false && strpos($source,"$". "role !== 2")!==false,
  'Usuario y colegio desde la sesión'=>strpos($source,"login_school_id")!==false && strpos($source,"login_id")!==false,
  'No acepta teacher_id desde el móvil'=>strpos($source,"$_POST['teacher_id']")===false,
  'Contraseña actual'=>strpos($source,"current_password")!==false && strpos($source,"password_verify(")!==false,
  'Compatibilidad con MD5 heredado'=>strpos($source,"md5($". "currentPassword)")!==false,
  'Hash nuevo seguro'=>strpos($source,"password_hash(")!==false,
  'Sentencia preparada'=>strpos($source,"$". "conn->prepare(")!==false,
  'No devuelve hash'=>strpos($source,"'password_hash' =>")===false,
  'Respuesta JSON'=>strpos($source,"json_encode(")!==false,
  'Reglas de nueva clave'=>strpos($source,"confirm_password")!==false &&
      strpos($source,"preg_match(")!==false,
  'Control de simultaneidad'=>strpos($source,"AND password=?")!==false
];
$bad=0;
foreach($checks as $name=>$ok){
  echo ($ok?'OK':'FALLO').': '.$name.PHP_EOL;
  if(!$ok)$bad++;
}
exit($bad?1:0);
