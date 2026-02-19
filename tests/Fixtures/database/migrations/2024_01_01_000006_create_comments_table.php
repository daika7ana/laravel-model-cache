<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('post_id');
            $table->string('author');
            $table->text('body');
            $table->unsignedBigInteger('commentable_id')->nullable();
            $table->string('commentable_type')->nullable();
            $table->timestamps();

            $table->foreign('post_id')->references('id')->on('posts')->onDelete('cascade');
            $table->index(['commentable_id', 'commentable_type']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('comments');
    }
};
