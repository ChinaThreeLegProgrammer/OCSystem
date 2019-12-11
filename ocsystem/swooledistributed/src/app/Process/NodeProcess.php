<?php
/**
 * Created by PhpStorm.
 * User: suzhixiang
 * 交易相关操作自定义进程
 * Date: 18-8-17
 * Time: 下午1:44
 */

namespace app\Process;

use Server\Components\CatCache\CatCacheRpcProxy;
use app\Models\Trading\ValidationModel;
use app\Models\Trading\TradingEncodeModel;
use Server\Components\Process\Process;

//自定义进程
use app\Process\VoteProcess;
use app\Process\SuperNoteProcess;
use app\Process\TimeClockProcess;
use Server\Components\Process\ProcessManager;
use MongoDB;

class NodeProcess extends Process
{

    /**
     * 存储数据库对象
     * @var
     */
    private $MongoDB;

    /**
     * 确认交易数据集合
     * @var
     */
    private $Node;

    /**
     * 存储数据库连接地址
     * @var
     */
    private $MongoUrl;

    /**
     * 节点缓存
     * @var
     */
    private $NodeCache = [];
    /**
     * 初始化函数
     * @param $process
     */
    public function start($process)
    {
        var_dump('NoteProcess');
//        $this->MongoUrl = 'mongodb://localhost:27017';
        $this->MongoUrl = 'mongodb://' . MONGO_IP . ":" . MONGO_PORT;
        $this->MongoDB = new \MongoDB\Client($this->MongoUrl);
        $this->Node = $this->MongoDB->selectCollection('nodes', 'node');
    }

    /**
     * 创建节点索引
     * @return bool
     */
    public function createdNodeIndex()
    {
        $this->Node->createIndexes(
            [
                ['key' => ['address' => 1]]
            ]
        );
        return returnSuccess();
    }

    /**
     * 获取多条已经入库的交易
     * @param array $where
     * @param array $data
     * @param int $page
     * @param int $pagesize
     * @param array $sort
     * @return bool
     */
    public function getNodeList($where = [], $data = [], $page = 1, $pagesize = 10000, $sort = [])
    {
        $list_res = [];//查询结果
        //查询条件
        $filter = $where;
        $options = [
            'projection'    =>  $data,
            'limit'         =>  $pagesize,
            'skip'          =>  ($page - 1) * $pagesize,
        ];
        //获取数据
        $list_res = $this->Node->find($filter, $options)->toArray();
        if(!empty($list_res)){
            //把数组对象转为数组
            $list_res = objectToArray($list_res);
        }
        return returnSuccess($list_res);
    }

    /**
     * 获取单条交易数据
     * @param array $where
     * @param array $data
     * @param array $order_by
     * @return bool
     */
    public function getNodeInfo($where = [], $data = [], $order_by = [])
    {
        $list_res = [];//查询结果
        //查询条件
        $filter = $where;
        $options = [
            'projection'    =>  $data,
            'sort'          =>  $order_by,
        ];
        //获取数据
        $list_res = $this->Node->findOne($filter, $options);
        if(!empty($list_res)){
            //把数组对象转为数组
            $list_res = objectToArray($list_res);
        }
        return returnSuccess($list_res);
    }

    /**
     * 修改单条数据
     * @param array $vote
     * @return bool
     */
    public function updateNode($where = [], $data = [])
    {
        $insert_res = $this->Node->updateOne($where, $data);
        if(!$insert_res->isAcknowledged()){
            return returnError('插入失败!');
        }
        return returnSuccess();
    }

    /**
     * 批量修改数据
     * @param array $vote
     * @return bool
     */
    public function updateNodeMany($where = [], $data = [])
    {
        $insert_res = $this->Node->updateMany($where, $data);
        if(!$insert_res->isAcknowledged()){
            return returnError('插入失败!');
        }
        return returnSuccess();
    }

    /**
     * 插入单条数据
     * @param array $vote
     * @return bool
     */
    public function insertNode($vote = [])
    {
        if(empty($vote)) return returnError('交易内容不能为空.');
        $insert_res = $this->Node->insertOne($vote);
        if(!$insert_res->isAcknowledged()){
            return returnError('插入失败!');
        }
        return returnSuccess(['id' => $insert_res->getInsertedId()->__toString()]);
    }

    /**
     * 插入多条数据
     * @param array $vote
     * @return bool
     */
    public function insertNodeMany($votes = [], $get_ids = false)
    {
        if(empty($votes)) return returnError('交易内容不能为空.');
        $insert_res = $this->Node->insertMany($votes);
        if(!$insert_res->isAcknowledged()){
            return returnError('插入失败!');
        }
        $ids = [];
        if($get_ids){
            foreach ($insert_res->getInsertedIds() as $ir_val){
                $ids[] = $ir_val;
            }
        }
        return returnSuccess(['ids' => $ids]);
    }

    /**
     * 删除单条数据
     * @param array $delete_where
     * @return bool
     */
    public function deleteNodePool(array $delete_where = [])
    {
        if(empty($delete_where)){
            return returnError('请传入删除的条件.');
        }
        $delete_res = $this->Node->deleteOne($delete_where);
        if(!$delete_res){
            return returnError('删除失败!');
        }
        return returnSuccess();
    }

    /**
     * 删除多条数据
     * @param array $delete_where
     * @return bool
     */
    public function deleteNodePoolMany(array $delete_where = [])
    {
        $delete_res = $this->Node->deleteMany($delete_where);
        if(!$delete_res){
            return returnError('删除失败!');
        }
        return returnSuccess();
    }

    /**
     * 更新超级节点
     * 该方法一定要在每轮节点健康检查方法执行之后再执行
     * @param int $rounds
     * @oneWay
     */
    public function rotationSuperNode(int $rounds = 1)
    {
        //获取此轮参与投票的节点数
        $nodes = [];//存储参与竞选的节点
        $del_res = [];//删除超级节点结果
        $super_nodes = [];//超级节点
        $node_rounds = 0;//当前节点所在顺位顺序
        $new_super_node = [];//新的超级节点
        $node_where = ['state' => true];//查询条件
        $node_data = ['ip' => 1, 'port' => 1, 'address' => 1, '_id' => 0, 'pledge' => 1];//查询字段
        $nodes = $this->getNodeList($node_where, $node_data);
        if(count($nodes['Data']) < 1){
            //少于21个节点参选，不进行统计
            return returnError();
        }
        foreach ($nodes['Data'] as $nd_key => $nd_val){
            $super_nodes[] = $nd_val['address'];
            $new_super_node[$nd_val['address']]['ip'] = $nd_val['ip'];
            $new_super_node[$nd_val['address']]['port'] = $nd_val['port'];
            //取质押的40%
            $new_super_node[$nd_val['address']]['value'] = floor(array_sum(array_column($nd_val['pledge'], 'value')) * 0.4);
            $new_super_node[$nd_val['address']]['voterNum'] = 0;
        }
        //先获取下一轮的投票结果,先设定获取一百万条数据
        $incentive_users = [];//可以享受激励的一千个用户地址
        $vote_where = ['rounds' => $rounds, 'address' => ['$in' => $super_nodes]];
        $vote_sort = ['value' => -1];
        $vote_res = ProcessManager::getInstance()
                                    ->getRpcCall(VoteProcess::class)
                                    ->getVoteList($vote_where, [], 1, 3000000, $vote_sort);
        if(!empty($vote_res['Data'])){
            //有投票数据
            foreach ($vote_res['Data'] as $vr_key => $vr_val){
                //组装各节点所有用户投票数据
                if($vr_val['value'] >= 1000000000000){
                    $incentive_users[$vr_val['address']][] = [
                        'address'   => $vr_val['voter'],
                        'value'     => number_format($vr_val['value'], 0, '', ''),
                    ];
                }
                $new_super_node[$vr_val['address']]['value'] += floor(50000000000 * 0.6);
                ++$new_super_node[$vr_val['address']]['voterNum'];
            }
        }
        //对值进行排序
        array_multisort(array_column($new_super_node,'value'),SORT_DESC,$new_super_node);
        //获取前30个节点
        $new_super_node = array_slice($new_super_node, 0, 30);
        //执行节点健康检查函数
        $count = 1;
        foreach ($new_super_node as $nsn_key => $nsn_val){
            if($nsn_key == get_instance()->config['address']){
                $node_rounds = $count;
            }
            $new_super_node[$nsn_key]['voters'] = $incentive_users[$nsn_key] ?? [];
            $new_super_node[$nsn_key]['address'] = $nsn_key;
            $new_super_node[$nsn_key]['value'] -= floor(0.4 * count($new_super_node[$nsn_key]['voters']));
            $new_super_node[$nsn_key]['value'] = number_format($new_super_node[$nsn_key]['value'], 0, '', '');
            ++$count;
        }
        //获取核心节点个数
        $core_node_num = ProcessManager::getInstance()
                                        ->getRpcCall(TimeClockProcess::class)
                                        ->getCoreNodeNum();
        //更新缓存
        CatCacheRpcProxy::getRpc()['SuperNode'] = [];
        CatCacheRpcProxy::getRpc()['SuperNode'] = array_keys(array_slice($new_super_node, 0, $core_node_num));
        $new_super_node = array_values($new_super_node);
        //先删除超级节点数据
        $del_res = ProcessManager::getInstance()
                        ->getRpcCall(SuperNodeProcess::class)
                        ->deleteSuperNodePoolMany();
        if(!$del_res['IsSuccess']){
            return returnError('删除旧数据失败!');
        }
        //插入新的超级节点数据
        ProcessManager::getInstance()
                        ->getRpcCall(SuperNodeProcess::class)
                        ->insertSuperNodeMany(array_slice($new_super_node, 0, $core_node_num));
        //删除旧的投票数据
        ProcessManager::getInstance()
                    ->getRpcCall(VoteProcess::class, true)
                    ->deleteVotePoolMany(['rounds' => ['$lt' => $rounds - 1]]);
        var_dump('over2');
        return returnSuccess(['superNode' => $new_super_node, 'index' => $node_rounds]);
    }

    /**
     * 对节点进行健康检查,同时更新新的出块节点
     * @param array $nodes
     * @oneWay
     */
    public function examinationNode()
    {
        //先获取是所有节点数目
        $all_node = $this->getNodeList();
        if(empty($all_node['Data'])){
            return false;
        }
        //获取区块高度
        $block_height = ProcessManager::getInstance()
                                        ->getRpcCall(BlockProcess::class)
                                        ->getTopBlockHeight();
        $block_height += 63;
        foreach ($all_node['Data'] as $an_key => &$an_val){
            $count_val = 0;//存储累积的
            if(empty($an_val['pledge'])){
                //没有质押则删除该节点
                unset($all_node['Data'][$an_key]);
                continue;
            }
            //删除主键
            unset($all_node['Data'][$an_key]['_id']);
            foreach ($an_val['pledge'] as $av_key => &$av_val){
                if($av_val['lockTime'] <= $block_height){
                    //下一轮到期，则下一轮判断为过期，不再参加
                    unset($all_node['Data'][$an_key]['pledge'][$av_key]);
                    continue;
                }
                //未过期收集质押金额，判断是否还有资格参与超级节点精选
                $count_val += $av_val['value'];
            }
            $an_val['state'] = $count_val >= 3000000000000000 ? true : false;
//            $an_val['state'] = $count_val >= 30000 ? true : false;
        }
        //删除旧节点数据
        $this->deleteNodePoolMany([]);
        //把更新后的超级节点数据存入数据库
        $this->insertNodeMany($all_node['Data']);
        var_dump('over');
        return returnSuccess(['node' => $all_node['Data']]);
    }

    /**
     * 设置节点缓存
     * @param string $pledge
     * @return bool
     */
    public function setNodeCache($pledge = '')
    {
        if (!isset($this->NodeCache[$pledge])){
            $this->NodeCache[$pledge] = 1;
        }else{
            return returnError('该用户已经质押过数据,请等待节点确认.');
        }
        return returnSuccess();
    }

    /**
     * 清除超级节点质押请求缓存
     */
    public function clearNodeCache()
    {
        $this->NodeCache = [];
    }

    /**
     * 进程结束函数
     * @param $process
     */
    public function onShutDown()
    {
        echo "节点进程关闭.";
    }
}
