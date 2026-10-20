<?php
/**
 * Notícias de exemplo para desenvolvimento. Rodado via `make seed`
 * (wp eval-file). Tudo fica marcado com _farol_seed e sai com `make unseed`.
 *
 * As imagens vêm de picsum.photos; sem internet, os posts saem sem imagem.
 */

require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

$author = get_user_by( 'login', getenv( 'ADMIN_USER' ) ?: 'samuel' );

$stories = array(
	'politica' => array(
		array( 'Câmara aprova em primeiro turno proposta que amplia a tributação sobre grandes fortunas', 'Texto segue para o segundo turno na próxima semana; oposição tenta obstruir a votação.' ),
		array( 'Governo anuncia reajuste do salário mínimo acima da inflação pelo terceiro ano', 'Medida beneficia diretamente cerca de 59 milhões de pessoas, segundo o Dieese.' ),
		array( 'CPI ouve representantes de plataformas digitais sobre moderação de conteúdo', 'Parlamentares cobram transparência nos algoritmos de recomendação.' ),
		array( 'Movimentos sociais entregam ao Senado pauta pela redução da jornada de trabalho', 'Proposta de fim da escala 6x1 reúne mais de 2 milhões de assinaturas.' ),
	),
	'parnamirim' => array(
		array( 'Prefeitura de Parnamirim abre consulta pública sobre o novo plano de mobilidade', 'Moradores podem enviar sugestões sobre linhas de ônibus e ciclovias até o fim do mês.' ),
		array( 'Feira da agricultura familiar ganha novo espaço no centro de Parnamirim', 'Produtores da região passam a vender às quartas e aos sábados.' ),
		array( 'Câmara Municipal debate ampliação da rede de creches em Parnamirim', 'Fila de espera por vagas na educação infantil passa de mil crianças.' ),
		array( 'Moradores de Parnamirim cobram drenagem após alagamentos em bairros da zona norte', 'Associações comunitárias organizam abaixo-assinado.' ),
	),
	'brasil'   => array(
		array( 'Chuvas no Sul deixam milhares de desalojados e reacendem debate sobre adaptação climática', 'Prefeituras pedem recursos federais para obras de contenção.' ),
		array( 'Programa de cisternas volta a crescer no semiárido nordestino', 'Agricultores familiares relatam ganhos de produtividade após dois anos de seca.' ),
		array( 'Professores da rede estadual entram em greve por recomposição salarial', 'Categoria aponta perdas acumuladas de 18% desde 2020.' ),
		array( 'SUS amplia oferta de medicamentos gratuitos pelo Farmácia Popular', 'Lista passa a incluir remédios para doenças crônicas e saúde mental.' ),
	),
	'tecnologia' => array(
		array( 'Projeto de lei quer obrigar big techs a remunerar o jornalismo no Brasil', 'Proposta prevê negociação coletiva entre plataformas e veículos de imprensa.' ),
		array( 'Universidades públicas lançam rede de computação para pesquisa em inteligência artificial', 'Infraestrutura nacional reduz dependência de serviços estrangeiros.' ),
		array( 'Entregadores de aplicativo denunciam bloqueios automáticos sem direito de defesa', 'Trabalhadores pedem transparência nos critérios usados pelos algoritmos.' ),
		array( 'ANPD abre investigação sobre uso de dados pessoais para treinar modelos de IA', 'Autoridade quer saber se usuários foram informados e puderam recusar.' ),
	),
	'mundo'    => array(
		array( 'Cúpula do clima termina sem acordo sobre fundo para países em desenvolvimento', 'Nações do Sul Global acusam países ricos de descumprirem promessas de financiamento.' ),
		array( 'Trabalhadores de aplicativos fazem greve coordenada em cinco países', 'Entregadores reivindicam vínculo empregatício e remuneração mínima por hora.' ),
		array( 'ONU alerta para agravamento da crise humanitária e pede cessar-fogo imediato', 'Agências relatam falta de água, comida e medicamentos para a população civil.' ),
		array( 'Eleições na América Latina testam a força das coalizões progressistas', 'Analistas veem disputa apertada e alta abstenção entre os jovens.' ),
	),
);

$body = <<<HTML
<!-- wp:paragraph --><p>Este é um texto de exemplo para conferir a tipografia e o ritmo de leitura do portal. O primeiro parágrafo resume o fato principal: quem, o quê, quando e onde, em poucas linhas.</p><!-- /wp:paragraph -->
<!-- wp:paragraph --><p>O segundo parágrafo traz contexto e a fala de uma fonte. Um bom texto de notícia vai do mais importante para o menos importante, para que o leitor possa parar a qualquer momento sem perder o essencial.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h2 class="wp-block-heading">O que está em jogo</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Depois do intertítulo, a matéria aprofunda os números, a história do caso e os desdobramentos esperados nos próximos dias.</p><!-- /wp:paragraph -->
<!-- wp:quote --><blockquote class="wp-block-quote"><!-- wp:paragraph --><p>Uma citação em destaque, com a voz de quem é diretamente afetado pela notícia.</p><!-- /wp:paragraph --><cite>Fonte ouvida pela reportagem</cite></blockquote><!-- /wp:quote -->
<!-- wp:paragraph --><p>O fechamento indica o próximo passo: votação, julgamento, reunião ou prazo que o leitor deve acompanhar.</p><!-- /wp:paragraph -->
HTML;

$only = getenv( 'SEED_ONLY' );
if ( $only ) {
	$stories = array_intersect_key( $stories, array_flip( explode( ',', $only ) ) );
}

$n = 0;
foreach ( $stories as $slug => $items ) {
	$cat = get_category_by_slug( $slug );
	if ( ! $cat ) {
		WP_CLI::warning( "Categoria '$slug' não existe — rode make categories." );
		continue;
	}

	foreach ( $items as $i => [ $title, $excerpt ] ) {
		$n++;
		$id = wp_insert_post(
			array(
				'post_title'    => $title,
				'post_excerpt'  => $excerpt,
				'post_content'  => $body,
				'post_status'   => 'publish',
				'post_author'   => $author ? $author->ID : 1,
				'post_category' => array( $cat->term_id ),
				// Espalha as datas para a capa ter uma ordem crível.
				'post_date'     => wp_date( 'Y-m-d H:i:s', time() - ( $n * 47 * MINUTE_IN_SECONDS ) ),
				'meta_input'    => array( '_farol_seed' => 1 ),
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			WP_CLI::warning( $id->get_error_message() );
			continue;
		}

		$image = media_sideload_image( "https://picsum.photos/seed/farol-$slug-$i/1600/900.jpg", $id, $title, 'id' );
		if ( is_wp_error( $image ) ) {
			WP_CLI::warning( "Sem imagem para \"$title\": " . $image->get_error_message() );
		} else {
			update_post_meta( $image, '_farol_seed', 1 );
			update_post_meta( $image, '_wp_attachment_image_alt', 'Imagem ilustrativa' );
			set_post_thumbnail( $id, $image );
		}

		WP_CLI::log( "✓ [$slug] $title" );
	}
}

WP_CLI::success( "$n notícias de exemplo criadas." );
