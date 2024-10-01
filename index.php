<?php
require 'vendor/autoload.php';

use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\String\Slugger\AsciiSlugger;

use League\CommonMark\Environment\Environment as CmEnv;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\FrontMatter\FrontMatterExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\Extension\FrontMatter\Output\RenderedContentWithFrontMatter;
use League\CommonMark\MarkdownConverter;

use Twig\Environment as TwigEnv;
use Twig\Loader\FilesystemLoader;
use Twig\Extra\String\StringExtension;

$start = microtime(true);
$startMem = round(memory_get_usage()/1048576,2); 
echo "\n Memory Consumption is   ";
echo $startMem .''.' MB';

// Settings - could be user defined
$sourceRoot = ".";
$outputRoot = ".";
$outputDir = "_site";
$ignores = ['vendor'];
//$mediaDir = 'media';
$templatesDir = __DIR__ . '/themes/default/templates';

/*
if (isset($argv[1])) {
    $outputDir = $argv[1];
}
*/

echo "\n outputdir: " . $outputDir;

// SETTING UP MarkDown converter
// Define your configuration, if needed
// Configure the Environment with all the CommonMark parsers/renderers
// Add the extensions
$config = [];
$environment = new CmEnv($config);
$environment->addExtension(new CommonMarkCoreExtension());
$environment->addExtension(new GithubFlavoredMarkdownExtension());
$environment->addExtension(new FrontMatterExtension());
$converter = new MarkdownConverter($environment);

// SETTING UP Twig 
$loader = new FilesystemLoader($templatesDir);
$twig = new TwigEnv($loader);
$twig->addExtension(new StringExtension());
$twig->addExtension(new \Twig\Extension\DebugExtension());

echo "\n\nSetup ready in " . (microtime(true) - $start) . " seconds";

//JSON content - read
$metaJson = file_get_contents($sourceRoot. '/_data/metadata.json');
$metadata = json_decode($metaJson, true);
$menuJson = file_get_contents($sourceRoot . '/_data/menus.json');
$menus = json_decode($menuJson, true);
/*
//Copy media folder
$src = $sourceRoot . '/' . $mediaDir . '/';
$dest = $outputRoot . '/' . $outputDir . '/' . $mediaDir . '/';
$fileSystem = new Symfony\Component\Filesystem\Filesystem();
$fileSystem->mirror($src, $dest);
*/

//copy assets
$fileSystem = new Symfony\Component\Filesystem\Filesystem();
$src = './assets/';
$dest = $outputRoot . '/' . $outputDir . '/assets/';
//echo "\n src: " . $src . " dest: " . $dest;
$fileSystem->mirror($src, $dest);

//copy robots.txt
copy('./assets/robots.txt', $outputRoot . '/' . $outputDir .  '/robots.txt');
$sitemapLine = "Sitemap: " . $metadata["url"] ."sitemap.xml";
file_put_contents($outputRoot . '/' . $outputDir . '/robots.txt', $sitemapLine.PHP_EOL , FILE_APPEND | LOCK_EX);

echo "\n\nStatic copied in " . (microtime(true) - $start) . " seconds";

$collections = [];

//MARKDOWN - read files in source directories
$dir = new FilesystemIterator($sourceRoot);
foreach ($dir as $file) {
    $name = strtolower($file->getFilename());
    echo "\n" . $name;
    if(is_dir($file) && !str_starts_with($name,'.') && !str_starts_with($name,'_') && !in_array($name, $ignores)) {
        $collections[$name] = [];
        readMarkdownFiles($sourceRoot, $name, $outputDir, $converter, $collections);
    }
}
readMarkdownFiles($sourceRoot, ".", $outputDir, $converter, $collections);

echo "\n\nMarkdown files read in " . (microtime(true) - $start) . " seconds";

//reorder the collections by date desc
foreach($collections as $key => $collection) {
    if($key != "tags") {
        array_multisort(array_map(function($element) {
            if(array_key_exists("date", $element)) {
                return $element['date'];
            }
            else {
                return 0;
            }
        }, $collection), SORT_DESC, $collection);     
        $collections[$key] = $collection;
    }
}

// then loop through each source collection to determine prev/next
foreach($collections as $key => $collection) {
    if($key != "tags") {
        $prevPagePermalink = "";
        $prevPageTitle = "";
        $nextPagePermalink = "";
        $nextPageTitle = "";
        $index = 0;

        $keys = array_keys($collection);
        $length = count($keys);
        foreach(array_keys($keys) AS $k ){
            $collection[$keys[$k]]["nextPagePermalink"] = $nextPagePermalink;
            $collection[$keys[$k]]["nextPageTitle"] = $nextPageTitle;
            $nextPagePermalink = $collection[$keys[$k]]["permalink"];
            $nextPageTitle = "";
            if (array_key_exists("title",$collection[$keys[$k]])){
                $nextPageTitle = $collection[$keys[$k]]["title"];
            }

            if($k+1 < $length) {
                $collection[$keys[$k]]["prevPagePermalink"] = $collection[$keys[$k+1]]["permalink"];
                $collection[$keys[$k]]["prevPageTitle"] = "";
                if (array_key_exists("title",$collection[$keys[$k+1]])){
                    $collection[$keys[$k]]["prevPageTitle"] = $collection[$keys[$k+1]]["title"];
                }
            }
        }
        $collections[$key] = $collection;
    }
}

echo "\n\nArray reordered in " . (microtime(true) - $start) . " seconds";

processMarkdown($outputRoot, $outputDir, $converter, $twig, $metadata, $menus, $collections);

echo "\n\nConversion completed in " . (microtime(true) - $start) . " seconds";
$endMem = round(memory_get_usage()/1048576,2); 
echo "\nMemory Consumption is   ";
echo $endMem .''.' MB';
echo "\nDifference: " . ($endMem - $startMem);

function readMarkdownFiles($sourceRoot, $sourceDir, $outputDir, $converter, &$completeCollection) {
    $sourceFullPath = $sourceRoot . '/' . $sourceDir . '/';
    $rii;

    if ($sourceDir == ".") {
        $sourceFullPath = $sourceRoot . '/';
        $rii = new FilesystemIterator($sourceRoot);
    }
    else {
        $rdi = new RecursiveDirectoryIterator($sourceFullPath, RecursiveDirectoryIterator::SKIP_DOTS);
        $rii = new RecursiveIteratorIterator($rdi, RecursiveIteratorIterator::SELF_FIRST);
    }

    $collection = [];
    foreach ($rii as $file) {
        // store all item info an array to return to the main function for later use
        if ($file->isFile() && $file->getExtension() === 'md') {
            //trying to get the path of the file from root folder (eg "archive/archive.md")
            //first get the real path of the of the file, then remove the real path of the sourceFullPath
            //this ends up with just the filename / path above the sourceDir eg just archive.md
            //so we add that back in again to get "archive/archive.md"
            $path;
            $markdownContent;

            if($sourceDir == ".") {
                $path = str_replace('\\','/', str_replace(realpath($sourceFullPath),'',realpath($file->getPathname())));
                $markdownContent = file_get_contents(str_replace('//','/', $sourceFullPath . $path));           
            }
            else {
                $path = str_replace('\\','/',$sourceDir . str_replace(realpath($sourceFullPath),'',realpath($file->getPathname())));
                $markdownContent = file_get_contents(str_replace($sourceDir . '/', '', $sourceFullPath) . $path);           
            }
            $result = $converter->convert($markdownContent);

            $frontMatter = [];
            if ($result instanceof RenderedContentWithFrontMatter) {
                $frontMatter = $result->getFrontMatter();
            }

            $htmlContent = $result->getContent();

            //set template to pages by default
            $frontMatter["template"] = 'pages.html.twig';
            if($sourceDir == "posts") {
                $frontMatter["template"] = "posts.html.twig";
            }
            else if($sourceDir == ".") {
                if(strtolower($path) == "/archive.md"){
                    $frontMatter["template"] = "archive.html.twig";
                }
                else if(strtolower($path) == "/index.md"){
                    $frontMatter["template"] = "home.html.twig";
                } 
                else if(strtolower($path) == "/404.md"){
                    $frontMatter["template"] = "pages.html.twig";
                } 
            }
            
            //page.url contains the URL as it should be from the path and filename
            //permalink is the actual URL of the page, taking into account any frontmatter settings
            $permalink = "";
            $page = [];
            $relativePath = str_replace($sourceDir, '', $path);
            $pageUrl = '/' . $sourceDir . str_replace('.md', '', $relativePath) . '/';
            if ($sourceDir == "."){
                if($path == "/index.md") {
                    $pageUrl = "/";
                }
                else if($path == "/404.md") {
                    $pageUrl = "/404.html";
                } 
                else {
                    $pageUrl = str_replace('.md', '', $path) . '/';
                }
            }
            $page['url'] = $pageUrl;
            $frontMatter["permalink"] = $page['url'];
            $tmplVars = $frontMatter;
            $tmplVars['content'] = $htmlContent;

            $completeCollection[$sourceDir][$path] = $tmplVars;
            $collection[$path] = $frontMatter;
        }
    }
}

function renderTwig($twig, $outputRoot, $outputDir, $tmplVars){
    $outputPath =  $outputRoot . '/' . $outputDir . '/' . $tmplVars['permalink'];

    //if output path includes an extention (ie a ".") simply create it, don't create index.html in subfolder
    //deal with "." in folder names? 
    //    eg "/some.folder/" should be "/some.folder/index.html", 
    //    but "/sitemap.xml" should remain "/sitemap.xml"
    if(!strpos($outputPath,".", (is_null(strrpos($outputPath, "/"))?0:strrpos($outputPath, "/")))){
        $outputPath = $outputPath . 'index.html'; 
    }

    if (!is_dir(dirname($outputPath))) {
        mkdir(dirname($outputPath), 0777, true);
    }
    
    file_put_contents($outputPath, $twig->render($tmplVars["template"], $tmplVars));
}

function processPagination($twig, $outputRoot, $outputDir, $tmplVars, $completeCollection){
    $data = $tmplVars['pagination']['data'];

    //hard coded to only paginate for posts and all one page (ie to create a list)
    $alias = 'posts';
    $size = 0; //default to all on one page
    $tmplVars['pagination']['total'] = count($completeCollection['posts']);
    $tmplVars[$alias] = $completeCollection['posts'];
    $tmplVars['pagination']['pages'] = 1;
    $tmplVars['pagination']['current'] = 1;
    renderTwig($twig, $outputRoot, $outputDir, $tmplVars);
}

function processMarkdown($outputRoot, $outputDir, $converter, $twig, $metadata, $menus, $completeCollection) {
   //sort tags array by key
    foreach($completeCollection as $srcKey => $src) {
        foreach($src as $key => $item) {

            //var_dump($item);
            $tmplVars = $item;
            $tmplVars['metadata'] =  $metadata; 
            $tmplVars['menus'] =  $menus; 
            $tmplVars['collections'] =  $completeCollection; 

            //check if there is pagination object and data object
            if(array_key_exists('pagination',$tmplVars) && array_key_exists('data',$tmplVars['pagination'])){
                // for pages listing posts per tag, 
                // need to loop through this section,
                // eg if pagination data = "tags"???
                $data = $tmplVars['pagination']['data'];
                echo "\n\n keyyyyy: " . $srcKey;
                processPagination($twig, $outputRoot, $outputDir, $tmplVars, $completeCollection);
            }
            else {
                renderTwig($twig, $outputRoot, $outputDir, $tmplVars);
            }
        }

        //create sitemap
        $item = [];
        $item["title"] = "Sitemap";
        $item["template"] = "sitemap.html.twig";
        $item["permalink"] = "/sitemap.xml";
        $tmplVars = $item;
        $tmplVars['metadata'] =  $metadata; 
        $tmplVars['menus'] =  $menus; 
        $tmplVars['collections'] =  $completeCollection; 
        renderTwig($twig, $outputRoot, $outputDir, $tmplVars);

        //create RSS feed
        $destFile = $outputRoot . '/' . $outputDir . '/feed/index.xml';
        $destPath = pathinfo($destFile);
        if (!file_exists($destPath['dirname'])) {
            mkdir($destPath['dirname']);
        }
        copy('./feed/.htaccess', $outputRoot . '/' . $outputDir . '/feed/.htaccess');
        copy('./feed/pretty-feed-v3.xsl', $outputRoot . '/' . $outputDir . '/feed/pretty-feed-v3.xsl');
        $item = [];
        $item["title"] = "RSS Feed";
        $item["template"] = "feed.html.twig";
        $item["permalink"] = "/feed/index.xml";
        $tmplVars = $item;
        $tmplVars['metadata'] =  $metadata; 
        $tmplVars['menus'] =  $menus; 
        $tmplVars['collections'] =  $completeCollection; 
        renderTwig($twig, $outputRoot, $outputDir, $tmplVars);  

    }
}
?>