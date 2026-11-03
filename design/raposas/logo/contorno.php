<?php
$img = imagecreatefrompng(''.__DIR__.'/../../../public/core/brand/logo.png');
imagepalettetotruecolor($img);
$isLilac = function($x,$y) use ($img) { $c=imagecolorat($img,$x,$y); $r=($c>>16)&255;$g=($c>>8)&255;$b=$c&255; return abs($r-0xb3)<14 && abs($g-0xa4)<14 && abs($b-0xee)<14; };
$isDark = function($x,$y) use ($img) { $c=imagecolorat($img,$x,$y); $r=($c>>16)&255;$g=($c>>8)&255;$b=$c&255; return abs($r-0x24)<10 && abs($g-0x15)<10 && abs($b-0x6b)<12; };
for ($y = 30; $y <= 470; $y += 6) {
  $xs = []; for ($x=0;$x<500;$x++) if ($isLilac($x,$y)) $xs[]=$x;
  $ds = []; for ($x=0;$x<500;$x++) if ($isDark($x,$y)) $ds[]=$x;
  // agrupa em trechos
  $runs = function($xs) { $r=[]; $s=null; $p=null; foreach ($xs as $x) { if ($s===null){$s=$x;} elseif ($x!=$p+1){$r[]="$s-$p"; $s=$x;} $p=$x; } if ($s!==null) $r[]="$s-$p"; return implode(' ',$r); };
  echo str_pad($y,4)." lilas: ".$runs($xs)."   | escuro: ".$runs($ds)."\n";
}
