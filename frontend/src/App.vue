<template>
  <div class="app-container">
    <el-header class="header">
      <h1>📦 订单状态机流转系统</h1>
      <div class="header-info">
        <el-tag type="success">Vue 3</el-tag>
        <el-tag type="primary">PHP 7.4+</el-tag>
        <el-tag type="warning">状态机</el-tag>
      </div>
    </el-header>
    <el-main class="main">
      <OrderList />
    </el-main>
  </div>
</template>

<script setup>
import OrderList from './components/OrderList.vue'
</script>

<style>
* {
  margin: 0;
  padding: 0;
  box-sizing: border-box;
}

body {
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
  background-color: #f5f7fa;
}

.app-container {
  min-height: 100vh;
}

.header {
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  color: #fff;
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 24px;
  height: 64px;
}

.header h1 {
  font-size: 20px;
  margin: 0;
}

.header-info {
  display: flex;
  gap: 8px;
}

.main {
  padding: 24px;
}
</style>
