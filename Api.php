<?php

namespace addons\cms\controller;

use addons\cms\model\Diydata;
use addons\cms\model\Modelx;
use think\Config;
use think\Db;
use think\Exception;
use think\exception\PDOException;
use addons\cms\model\Archives as ArchivesModel;
use addons\cms\model\Channel;


/**
 * Api接口控制器
 * Class Api
 * @package addons\cms\controller
 */
class Api extends Base
{

    public function _initialize()
    {
        Config::set('default_return_type', 'json');

        $apikey = $this->request->request('apikey');
        $config = get_addon_config('cms');
        if (!$config['apikey']) {
            $this->error('请先在后台配置API密钥');
        }
        if ($config['apikey'] != $apikey) {
            $this->error('密钥不正确');
        }

        return parent::_initialize();
    }

    /**
     * 文档数据写入接口
     */
    public function index()
    {

        $data = $this->request->request();
        if (isset($data['user']) && $data['user']) {
            $user = \app\common\model\User::where('nickname', $data['user'])->find();
            if ($user) {
                $data['user_id'] = $user->id;
            }
        }
        //如果有传栏目名称
        if (isset($data['channel']) && $data['channel']) {
            $channel = \addons\cms\model\Channel::where('name', $data['channel'])->where('type', 'list')->find();
            if ($channel) {
                $data['channel_id'] = $channel->id;
            } else {
                $this->error('栏目未找到');
            }
        } else {
            $channel_id = $this->request->request('channel_id');
            $channel = \addons\cms\model\Channel::get($channel_id);
            if (!$channel) {
                $this->error('栏目未找到');
            }
        }
        $model = Modelx::get($channel['model_id']);
        if (!$model) {
            $this->error('模型未找到');
        }
        $data['model_id'] = $model['id'];
        $data['content'] = !isset($data['content']) ? '' : $data['content'];

        Db::startTrans();
        try {
            //副表数据插入会在模型事件中完成
            (new \app\admin\model\cms\Archives)->allowField(true)->save($data);
            Db::commit();
        } catch (PDOException $e) {
            Db::rollback();
            $this->error($e->getMessage());
        } catch (Exception $e) {
            Db::rollback();
            $this->error($e->getMessage());
        }
        $this->success('新增成功');
        return;
    }

    /**
     * 获取栏目列表
     */
    public function channel()
    {
        $channelList = \addons\cms\model\Channel::where('status', '<>', 'hidden')
            ->where('type', 'list')
            ->order('weigh DESC,id DESC')
            ->column('id,name');
        $this->success(__('读取成功'), null, $channelList);
    }

    /**
     * @desc 获取所有文章列表
     * @param $per_page 每页条数
     * @param $page 页码
     * */
    public function archives()
    {
        $per_page = $this->request->get('per_page', 20);
        $list = \addons\cms\model\Archives::where('status', 'normal')
            ->order('id', 'desc')
            ->paginate($per_page, false, ['type' => '\\addons\\cms\\library\\Bootstrap']);;
        $this->success(__('读取成功'), null, $list);
    }

    /**
     * @desc 获取单个文章详情
     * @param $id 文章id
     * */
    public function archive_detail()
    {
        $action = $this->request->post("action");
        if ($action && $this->request->isPost()) {
            return $this->$action();
        }
        $diyname = $this->request->param('diyname');
        if ($diyname && !is_numeric($diyname)) {
            $archives = ArchivesModel::getByDiyname($diyname);
        } else {
            $id = $diyname ? $diyname : $this->request->param('id', '');
            $archives = ArchivesModel::get($id, ['channel']);
        }

        if (!$archives || ($archives['user_id'] != $this->auth->id && $archives['status'] != 'normal') || $archives['deletetime']) {
            $this->error(__('No specified article found'));
        }
        $channel = Channel::get($archives['channel_id']);
        if (!$channel) {
            $this->error(__('No specified channel found'));
        }
        $model = Modelx::get($channel['model_id'], [], true);
        if (!$model) {
            $this->error(__('No specified model found'));
        }
        $addon = db($model['table'])->where('id', $archives['id'])->find();
        if ($addon) {
            if ($model->fields) {
                $fieldsContentList = $model->getFieldsContentList($model->id);
                ArchivesModel::appendTextAttr($fieldsContentList, $addon);

            }
            $archives->setData($addon);
        } else {
            $this->error('No specified addon article found');
        }
        $data['archives']  = $archives;
        $data['channel'] = $channel;
        $this->success(__('读取成功'), null, $data);
    }

    /**
     * 删除
     * @param mixed $ids
     */
    public function del()
    {
        $ids = $this->request->get('ids');
        \addons\cms\model\Archives::where('id', 'in', $ids)->delete();
        $this->success(__('成功'));
    }

    /**
     * 评论数据写入接口
     */
    public function comment()
    {
        try {
            $params = $this->request->post();
            \addons\cms\model\Comment::postComment($params);
        } catch (Exception $e) {
            $this->error($e->getMessage());
        }
        $this->success(__('评论成功'));
    }

    /**
     * 自定义表单数据写入接口
     */
    public function diyform()
    {
        $id = $this->request->request("diyform_id/d");
        $diyform = \addons\cms\model\Diyform::get($id);
        if (!$diyform || $diyform['status'] != 'normal') {
            $this->error("自定义表单未找到");
        }

        //是否需要登录判断
        if ($diyform['needlogin'] && !$this->auth->isLogin()) {
            $this->error("请登录后再操作");
        }

        $diydata = new Diydata($diyform->getData("table"));
        if (!$diydata) {
            $this->error("自定义表未找到");
        }

        $data = $this->request->request();
        try {
            $diydata->allowField(true)->save($data);
        } catch (Exception $e) {
            $this->error("数据提交失败");
        }
        $this->success("数据提交成功", $diyform['redirecturl'] ? $diyform['redirecturl'] : addon_url('cms/index/index'));
    }
}

