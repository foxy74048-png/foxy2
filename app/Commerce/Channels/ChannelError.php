<?php

namespace App\Commerce\Channels;

use RuntimeException;

/** Erro vindo do canal, com texto que o lojista entende (aparece na tela do anúncio ou da conexão). */
class ChannelError extends RuntimeException {}
