<?php
/**
 * Elementor Widget 类定义。
 *
 * 该文件仅在 Elementor 的 \Elementor\Widget_Base 已加载后，
 * 由 class-elementor.php 的 register_widgets() 在 elementor/widgets/register 钩子中引入，
 * 避免插件 require 阶段过早引用 Widget_Base 导致 fatal error。
 *
 * @package AT8_Moonlight_User_Center_Pro
 */

if (!defined('ABSPATH')) {
	exit;
}

abstract class AT8_MLUC_Elementor_Widget_Base extends \Elementor\Widget_Base
{
	abstract protected function shortcode_tag();

	public function get_icon()
	{
		return 'eicon-form-horizontal';
	}

	public function get_categories()
	{
		return array('at8-moonlight');
	}

	protected function register_controls()
	{
		$this->start_controls_section('section_content', array('label' => __('内容', 'at8-moonlight-user-center')));
		$this->add_control('note', array(
			'type' => \Elementor\Controls_Manager::RAW_HTML,
			'raw'  => __('该组件直接渲染对应前端表单，样式由主题配合。', 'at8-moonlight-user-center'),
		));
		$this->end_controls_section();
	}

	protected function render()
	{
		echo do_shortcode('[' . $this->shortcode_tag() . ']');
	}
}

class AT8_MLUC_Elementor_Login extends AT8_MLUC_Elementor_Widget_Base
{
	public function get_name()
	{
		return 'at8_mluc_login';
	}

	public function get_title()
	{
		return __('漫步白月光 · 登录表单', 'at8-moonlight-user-center');
	}

	protected function shortcode_tag()
	{
		return 'at8_mluc_login';
	}
}

class AT8_MLUC_Elementor_Register extends AT8_MLUC_Elementor_Widget_Base
{
	public function get_name()
	{
		return 'at8_mluc_register';
	}

	public function get_title()
	{
		return __('漫步白月光 · 注册表单', 'at8-moonlight-user-center');
	}

	protected function shortcode_tag()
	{
		return 'at8_mluc_register';
	}
}

class AT8_MLUC_Elementor_Profile extends AT8_MLUC_Elementor_Widget_Base
{
	public function get_name()
	{
		return 'at8_mluc_profile';
	}

	public function get_title()
	{
		return __('漫步白月光 · 用户资料卡', 'at8-moonlight-user-center');
	}

	protected function shortcode_tag()
	{
		return 'at8_mluc_account';
	}
}
